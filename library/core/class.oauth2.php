<?php
/**
 * @author Patrick Kelly <patrick.k@vanillaforums.com>
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 * @package Core
 * @since 2.0
 */

use Garden\Web\Exception\ClientException;
use Garden\Web\Exception\NotFoundException;
use Garden\Web\Exception\ServerException;
use Psr\Container\ContainerExceptionInterface;
use Vanilla\Permissions;

/**
 * Class Gdn_OAuth2
 *
 * Base class to be extended by any plugin that wants to use Oauth2 protocol for SSO.
 *
 * WARNING
 * This is a base class for the purposes of being extended by other plugins.
 * It is not to be instantiated on its own.
 *
 * For most OAuth2 SSO needs the generic plugins/OAuth2/class.Oauth2.plugin.php should
 * be adequate. If not, create a plugin that extends this class, Gdn_OAuth2, and overwrite
 * any of its methods of constants.
 *
 */
class Gdn_OAuth2 extends Gdn_Plugin implements \Vanilla\InjectableInterface {

    /** @var string token provided by authenticator  */
    protected $accessToken;

    /** @var array response to token request by authenticator  */
    protected $accessTokenResponse;

    /** @var string key for GDN_UserAuthenticationProvider table  */
    protected $providerKey = null;

    /** @var  string passing scope to authenticator */
    protected $scope;

    /** @var string content type for API calls */
    protected $defaultContentType = 'application/x-www-form-urlencoded';

    /** @var array stored information to connect with provider (secret, etc.) */
    protected $provider = [];

    /** @var array optional additional get parameters to be passed in the authorize_uri */
    protected $authorizeUriParams = [];

    /** @var array optional additional post parameters to be passed in the accessToken request */
    protected $requestAccessTokenParams = [];

    /** @var array optional additional get params to be passed in the request for profile */
    protected $requestProfileParams = [];

    /** @var  @var string optional set the settings view */
    protected $settingsView;

    /**
     * @var SsoUtils
     */
    private $ssoUtils;

    /**
     * @var \SessionModel
     */
    private $sessionModel;

    /**
     * @var \Garden\Container\Container
     */
    private $container;

    /**
     * Set up OAuth2 access properties.
     *
     * @param string $providerKey Fixed key set in child class.
     * @param bool|string $accessToken Provided by the authentication provider.
     */
    public function __construct($providerKey, $accessToken = false) {
        $this->providerKey = $providerKey;
        $this->provider = $this->provider();
        if ($accessToken) {
            // We passed in a connection
            $this->accessToken = $accessToken;
        }
    }

    /**
     * Generate a container key from a provider type.
     *
     * @param string $providerType The provider type (Gdn_AuthenticationProvider.AuthenticationSchemeAlias).
     * @return string
     */
    final protected static function containerKey($providerType): string {
        return "@oauth.{$providerType}";
    }

    /**
     * Setup
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Create the structure in the database.
     */
    public function structure() {
        // Make sure we have the OAuth2 provider.
        $provider = $this->provider();
        if (!val('AuthenticationKey', $provider)) {
            $model = new Gdn_AuthenticationProviderModel();
            $provider = [
                'AuthenticationKey' => $this->providerKey,
                'AuthenticationSchemeAlias' => $this->providerKey,
                'Name' => $this->providerKey,
                'AcceptedScope' => 'openid email profile',
                'ProfileKeyEmail' => 'email', // Can be overwritten in settings, the key the authenticator uses for email in response.
                'ProfileKeyPhoto' => 'picture',
                'ProfileKeyName' => 'nickname',
                'ProfileKeyFullName' => 'name',
                'ProfileKeyUniqueID' => 'sub'
            ];

            $model->save($provider);
        }
    }

    /**
     * Check if there is enough data to connect to an authentication provider.
     *
     * @return bool True if there is a secret and a client_id, false if not.
     */
    public function isConfigured() {
        $provider = $this->provider();
        return (val('AssociationSecret', $provider) && val('AssociationKey', $provider));
    }

    /**
     * Check if the provider is active.
     *
     * @return bool Returns **true** if the provider is active or **false** otherwise.
     */
    final public function isActive() {
        $provider = $this->provider();
        return !empty($provider['Active']);
    }

    /**
     * Check if an access token has been returned from the provider server.
     *
     * @return bool True of there is an accessToken, fals if there is not.
     */
    public function isConnected() {
        if (!$this->accessToken) {
            return false;
        }
        return true;
    }

    /**
     * Check authentication provider table to see if this is the default method for logging in.
     *
     * @return bool Return the value of the IsDefault row of GDN_UserAuthenticationProvider .
     */
    public function isDefault() {
        $provider = $this->provider();
        return val('IsDefault', $provider);
    }

    /**
     * Renew or return access token.
     *
     * @param bool|string $newValue Pass existing token if it exists.
     * @return bool|string|null String if there is an accessToken passed or found in session, false or null if not.
     */
    public function accessToken($newValue = false) {
        if (!$this->isConfigured() && $newValue === false) {
            return false;
        }

        if ($newValue !== false) {
            $this->accessToken = $newValue;
        }

        // If there is no token passed, try to retrieve one from the user's attributes.
        if ($this->accessToken === null && Gdn::session()->UserID) {
            // If this workflow uses a RefreshToken, regenerate the access token using the RefreshToken, otherwise use the stored AccessToken.
            $refreshToken = valr($this->getProviderKey().'.RefreshToken', Gdn::session()->User->Attributes);
            if ($refreshToken) {
                $response = $this->requestAccessToken($refreshToken, true);
                // save the new refresh_token if there is one and it is different from the existing one.
                if (val('refresh_token', $response) !== $refreshToken) {
                    $userModel = New UserModel();
                    $userModel->saveAttribute(Gdn::session()->UserID, [$this->getProviderKey() => ['RefreshToken' => val('refresh_token', $response)]]);
                }
                $this->accessToken = val('access_token', $response);
            } else {
                $this->accessToken = valr($this->getProviderKey().'.AccessToken', Gdn::session()->User->Attributes);
            }
        }

        return $this->accessToken;
    }


    /**
     * Set access token received from provider.
     *
     * @param string $accessToken Retrieved from provider to authenticate communication.
     * @return $this Return this object for chaining purposes.
     */
    public function setAccessToken($accessToken) {
        $this->accessToken = $accessToken;
        return $this;
    }


    /**
     * Set provider key used to access settings stored in GDN_UserAuthenticationProvider.
     *
     * @param string $providerKey Key to retrieve provider data hardcoded into child class.
     * @return $this Return this object for chaining purposes.
     */
    public function setProviderKey($providerKey) {
        $this->providerKey = $providerKey;
        return $this;
    }


    /**
     * Set scope to be passed to provider.
     *
     * @param string $scope.
     * @return $this Return this object for chaining purposes.
     */
    public function setScope($scope) {
        $this->scope = $scope;
        return $this;
    }


    /**
     * Set additional params to be added to the get string in the AuthorizeUri string.
     *
     * @param string $params.
     * @return $this Return this object for chaining purposes.
     */
    public function setAuthorizeUriParams($params) {
        $this->authorizeUriParams = $params;
        return $this;
    }


    /**
     * Set additional params to be to be merged with the default parameters
     * in the access token request.
     *
     * @param array $params Params to add to the access token request.
     *
     * @return $this Return this object for chaining purposes.
     */
    public function setRequestAccessTokenParams($params) {
        $this->requestAccessTokenParams = $params;
        return $this;
    }


    /**
     * Set additional params to be to be merged with the default parameters
     * in the getProfile request.
     *
     * @param array $params Params to add to the get profile request.
     *
     * @return $this Return this object for chaining purposes.
     */
    public function setRequestProfileParams(array $params) {
        $this->requestProfileParams = $params;
        return $this;
    }


    /**
     * Allow child classes to pass different options to the Token request API call.
     * Valid options are ConnectTimeout, Timeout, Content-Type and Authorization-Header-Message.
     *
     * @return array
     */
    public function getAccessTokenRequestOptions() {
        return [];
    }


    /**
     * Allow child classes to pass different options to the Profile request API call.
     * Valid options are ConnectTimeout, Timeout, Content-Type and Authorization-Header-Message.
     *
     * @return array
     */
    public function getProfileRequestOptions() {
        return [];
    }



    /** ------------------- Provider Methods --------------------- */

    /**
     *  Return all the information saved in provider table.
     *
     * @return array Stored provider data (secret, client_id, etc.).
     */
    public function provider() {
        if (!$this->provider) {
            $this->provider = Gdn_AuthenticationProviderModel::getProviderByKey($this->providerKey);
        }

        return $this->provider;
    }

    /**
     *  Get provider key.
     *
     * @return string Provider key.
     */
    public function getProviderKey() {
        return $this->providerKey;
    }

    /**
     * Register a call back function so that multiple plugins can use it as an entry point on SSO.
     *
     * This endpoint is executed on /entry/[provider] and is used as the redirect after making an
     * initial request to log in to an authentication provider.
     *
     * @param Gdn_PluginManager $sender
     */
    public function gdn_pluginManager_afterStart_handler($sender) {
        $sender->registerCallback("entryController_{$this->providerKey}Redirect_create", [$this, 'entryRedirectEndpoint']);
        $sender->registerCallback("entryController_{$this->providerKey}_create", [$this, 'entryEndpoint']);
        $sender->registerCallback("settingsController_{$this->providerKey}_create", [$this, 'settingsEndpoint']);
    }

    /** ------------------- Settings Related Methods --------------------- */

    /**
     * Allow child class to over-ride or add form fields to settings.
     *
     * @return array Form fields to appear in settings dashboard.
     */
    protected function getSettingsFormFields() {
        $promptOptions =  ['login' => 'login', 'none' => 'none', 'consent' => 'consent', 'consent and login' =>  'consent and login'];
        $formFields = [
            'RegisterUrl' => ['LabelCode' => 'Register Url', 'Description' => 'Enter the endpoint to direct a user to register.'],
            'SignOutUrl' => ['LabelCode' => 'Sign Out Url', 'Description' => 'Enter the endpoint to log a user out.'],
            'AcceptedScope' => ['LabelCode' => 'Request Scope', 'Description' => 'Enter the scope to be sent with Token Requests.'],
            'ProfileKeyEmail' => ['LabelCode' => 'Email', 'Description' => 'The Key in the JSON array to designate Emails'],
            'ProfileKeyPhoto' => ['LabelCode' => 'Photo', 'Description' => 'The Key in the JSON array to designate Photo.'],
            'ProfileKeyName' => ['LabelCode' => 'Display Name', 'Description' => 'The Key in the JSON array to designate Display Name.'],
            'ProfileKeyFullName' => ['LabelCode' => 'Full Name', 'Description' => 'The Key in the JSON array to designate Full Name.'],
            'ProfileKeyUniqueID' => ['LabelCode' => 'User ID', 'Description' => 'The Key in the JSON array to designate UserID.'],
            'Prompt' => ['LabelCode' => 'Prompt', 'Description' => 'Prompt Parameter to append to Authorize Url', 'Control' => 'DropDown', 'Items' => $promptOptions]
        ];
        return $formFields;
    }

    /**
     * Redirect to the OAuth redirect page with a verification nonce.
     *
     * @param EntryController $sender The controller initiating the request.
     * @param  string $state The state to pass along the OAuth2 flow.
     */
    public function entryRedirectEndpoint(\EntryController $sender, $state = '') {
        $state = $this->decodeState($state);
        $url = $this->realAuthorizeUri($state);

        \Vanilla\Web\CacheControlMiddleware::sendCacheControlHeaders(\Vanilla\Web\CacheControlMiddleware::NO_CACHE);
        redirectTo($url, 302, false);
    }


    /**
     * Create a controller to deal with plugin settings in dashboard.
     *
     * @param Gdn_Controller $sender.
     * @param Gdn_Controller $args.
     */
    public function settingsEndpoint($sender, $args) {
        $sender->permission('Garden.Settings.Manage');
        $model = new Gdn_AuthenticationProviderModel();

        /* @var Gdn_Form $form */
        $form = new Gdn_Form();
        $form->setModel($model);
        $sender->Form = $form;

        if (!$form->authenticatedPostBack()) {
            $provider = $this->provider();
            $form->setData($provider);
        } else {

            $form->setFormValue('AuthenticationKey', $this->getProviderKey());

            $sender->Form->validateRule('AssociationKey', 'ValidateRequired', 'You must provide a unique AccountID.');
            $sender->Form->validateRule('AssociationSecret', 'ValidateRequired', 'You must provide a Secret');
            $sender->Form->validateRule('AuthorizeUrl', 'isUrl', 'You must provide a complete URL in the Authorize Url field.');
            $sender->Form->validateRule('TokenUrl', 'isUrl', 'You must provide a complete URL in the Token Url field.');
            $sender->Form->validateRule('ProfileUrl', 'isUrl', 'You must provide a complete URL in the Profile Url field.');


            // To satisfy the AuthenticationProviderModel, create a BaseUrl.
            $baseUrlParts = parse_url($form->getValue('AuthorizeUrl'));
            $baseUrl = (val('scheme', $baseUrlParts) && val('host', $baseUrlParts)) ? val('scheme', $baseUrlParts).'://'.val('host', $baseUrlParts) : null;
            if ($baseUrl) {
                $form->setFormValue('BaseUrl', $baseUrl);
                $form->setFormValue('SignInUrl', $baseUrl); // kludge for default provider
            }
            if ($form->save()) {
                $sender->informMessage(t('Saved'));
            }
        }

        // Set up the form.
        $formFields = [
            'AssociationKey' =>  ['LabelCode' => 'Client ID', 'Description' => 'Unique ID of the authentication application.'],
            'AssociationSecret' =>  ['LabelCode' => 'Secret', 'Description' => 'Secret provided by the authentication provider.'],
            'AuthorizeUrl' =>  ['LabelCode' => 'Authorize Url', 'Description' => 'URL where users sign-in with the authentication provider.'],
            'TokenUrl' => ['LabelCode' => 'Token Url', 'Description' => 'Endpoint to retrieve the authorization token for a user.'],
            'ProfileUrl' => ['LabelCode' => 'Profile Url', 'Description' => 'Endpoint to retrieve a user\'s profile.'],
            'BearerToken' => ['LabelCode' => 'Authorization Code in Header', 'Description' => 'When requesting the profile, pass the access token in the HTTP header. i.e Authorization: Bearer [accesstoken]', 'Control' => 'checkbox']
        ];

        $formFields = $formFields + $this->getSettingsFormFields();

        $formFields['AllowAccessTokens'] = [
            'LabelCode' => 'Allow this connection to issue API access tokens.',
            'Control' => 'toggle'
        ];
        $formFields['IsDefault'] = ['LabelCode' => 'Make this connection your default signin method.', 'Control' => 'toggle'];

        $sender->setData('_Form', $formFields);

        $sender->setHighlightRoute();
        if (!$sender->data('Title')) {
            $sender->setData('Title', sprintf(t('%s Settings'), 'Oauth2 SSO'));
        }

        $view = ($this->settingsView) ? $this->settingsView : 'plugins/oauth2';

        // Create and send the possible redirect URLs that will be required by the authenticating server and display them in the dashboard.
        // Use Gdn::Request instead of convience function so that we can return http and https.
        $redirectUrls = Gdn::request()->url('/entry/'. $this->getProviderKey(), true, true);
        $sender->setData('redirectUrls', $redirectUrls);

        $sender->render('settings', '', $view);
    }



    /** ------------------- Connection Related Methods --------------------- */

    /**
     * Return the URL that sign-in buttons should use.
     *
     * @param array $state Optionally provide an array of variables to be sent to the provider.
     * @return string Returns the sign-in URL.
     */
    public function authorizeUri($state = []) {
        $params = empty($state) ? '' : '?'.http_build_query(['state' => $this->encodeState($state)]);

        return url("entry/{$this->providerKey}-redirect{$params}", true);
    }

    /**
     * Create the URI that can return an authorization.
     *
     * @param array $state Optionally provide an array of variables to be sent to the provider.
     *
     * @return string Endpoint of the provider.
     */
    protected function realAuthorizeUri(array $state = []): string {
        $provider = $this->provider();

        $uri = val('AuthorizeUrl', $provider);

        $redirect_uri = '/entry/'.$this->getProviderKey();
        $reponse_type = c('OAuth2.ResponseType', 'code');

        $defaultParams = [
            'response_type' => $reponse_type,
            'client_id' => val('AssociationKey', $provider),
            'redirect_uri' => url($redirect_uri, true),
            'scope' => val('AcceptedScope', $provider)
        ];
        // allow child class to overwrite or add to the authorize URI.
        $get = array_merge($defaultParams, $this->authorizeUriParams);

        $state['token'] = $this->ssoUtils->getStateToken();
        $get['state'] = $this->encodeState($state);

        if (array_key_exists('Prompt', $provider) && isset($provider['Prompt'])) {
            $get['prompt'] = $provider['Prompt'] ;
        }

        return $uri.'?'.http_build_query($get);
    }


    /**
     * Generic API uses ProxyRequest class to fetch data from remote endpoints.
     *
     * @param string $uri Endpoint on provider's server.
     * @param string $method HTTP method required by provider.
     * @param array $params Query string.
     * @param array $options Configuration options for the request (e.g. Content-Type).
     *
     * @return mixed.
     *
     * @throws Exception.
     * @throws Gdn_UserException.
     */
    protected function api($uri, $method = 'GET', $params = [], $options = []) {
        $proxy = new ProxyRequest();

        // Create default values of options to be passed to ProxyRequest.
        $defaultOptions['ConnectTimeout'] = 10;
        $defaultOptions['Timeout'] = 10;

        $headers = [];

        // Optionally over-write the content type
        if ($contentType = val('Content-Type', $options, $this->defaultContentType)) {
            $headers['Content-Type'] = $contentType;
        }

        // Obtionally add proprietary required Authorization headers
        if ($headerAuthorization = val('Authorization-Header-Message', $options, null)) {
            $headers['Authorization'] = $headerAuthorization;
        }

        // Merge the default options with the passed options over-writing default options with passed options.
        $proxyOptions = array_merge($defaultOptions, $options);

        $proxyOptions['URL'] = $uri;
        $proxyOptions['Method'] = $method;

        $this->log('Proxy Request Sent in API', ['headers' => $headers, 'proxyOptions' => $proxyOptions, 'params' => $params]);

        $response = $proxy->request(
            $proxyOptions,
            $params,
            null,
            $headers
        );

        // Extract response only if it arrives as JSON
        if (stripos($proxy->ContentType, 'application/json') !== false) {
            $this->log('API JSON Response', ['response' => $response]);
            $response = json_decode($proxy->ResponseBody, true);
        }

        // Return any errors
        if (!$proxy->responseClass('2xx')) {
            if (isset($response['error'])) {
                $message = 'Request server says: '.$response['error_description'].' (code: '.$response['error'].')';
            } else {
                $message = 'HTTP Error communicating Code: '.$proxy->ResponseStatus;
            }
            $this->log('API Response Error Thrown', ['response' => json_decode($response)]);
            throw new Gdn_UserException($message, $proxy->ResponseStatus);
        }

        return $response;
    }


    /**
     * Create a controller to handle entry request.
     *
     * @param Gdn_Controller $sender.
     * @param string $code Retrieved from the response of the authentication provider, used to fetch an authentication token.
     * @param string $state Values passed by us and returned in the response of the authentication provider.
     *
     * @throws Exception.
     * @throws Gdn_UserException.
     */
    public function entryEndpoint($sender, $code, $state = '') {
        if ($error = $sender->Request->get('error')) {
            throw new Gdn_UserException($error);
        }
        if (empty($code)) {
            throw new Gdn_UserException('The code parameter is either not set or empty.');
        }

        $response = $this->requestAccessToken($code);
        if (!$response) {
            throw new Gdn_UserException('The OAuth server did not return a valid response.');
        }

        if (!empty($response['error'])) {
            throw new Gdn_UserException($response['error_description']);
        } elseif (empty($response['access_token'])) {
            throw new Gdn_UserException('The OAuth server did not return an access token.', 400);
        } else {
            $this->accessToken($response['access_token']);
        }

        $this->log('Getting Profile', []);
        $profile = $this->getProfile();
        $this->log('Profile', $profile);

        if ($state) {
            $state = $this->decodeState($state);
        }

        $suppliedStateToken = $state['token'] ?? '';
        $this->ssoUtils->verifyStateToken($this->providerKey, $suppliedStateToken);

        // Save the access token and the profile to the session table, set expiry to 3 minutes.
        $expiryTime = new \DateTimeImmutable('now + 5 minutes');
        $stashID = $this->sessionModel->insert(
            [
                'Attributes' => [
                    'AccessToken' => $response['access_token'] ,
                    'RefreshToken' => $response['refresh_token'],
                    'Profile' => $profile,
                ],
                'DateExpires' => $expiryTime->format(MYSQL_DATE_FORMAT),
            ]
        );
        $url = '/entry/connect/'.$this->getProviderKey();

        // Pass the "sessionID" to in the query so that it can be retrieved.
        $url .= '?'.http_build_query(array_filter(['Target' => $state['target'] ?? '/', 'stashID' => $stashID]));
        // Redirect to the connect script.
        redirectTo($url);
    }


    /**
     * Inject into the process of the base connection.
     *
     * @param Gdn_Controller $sender.
     * @param Gdn_Controller $args.
     */
    public function base_connectData_handler($sender, $args) {
        if (val(0, $args) != $this->getProviderKey()) {
            return;
        }

        /* @var Gdn_Form $form */
        $form = $sender->Form; //new gdn_Form();

        if ($form->isPostBack()) {
            $stashID = $form->getFormValue('stashID');
        } else {
            $stashID = $sender->Request->get('stashID');
        }

        if (!$stashID) {
            $this->log('Missing stashID', ['POST' => $sender->Request->post(),'GET' => $sender->Request->get()]);
            throw new Gdn_UserException('Missing session, please go back and log in again.', 401);
        }

        $savedProfile = $this->sessionModel->getActiveSession($stashID);
        if ($savedProfile['Attributes']) {
            $this->log('Base Connect Data Profile Saved in Session', ['profile' => $savedProfile['Attributes']]);
        } else {
            $this->log('Base Connect Data Profile Not Found in Session', []);
        }

        // Retrieve the profile that was saved to the session in the entryEndPoint.
        $profile = $savedProfile['Attributes']['Profile'] ?? [];
        $accessToken = val('AccessToken', $savedProfile);
        $refreshToken = val('RefreshToken', $savedProfile);

        trace($profile, 'Profile');
        trace($accessToken, 'Access Token');
        trace($refreshToken, 'Refresh Token');


        // Create a form and populate it with values from the profile.
        $originaFormValues = $form->formValues();
        $formValues = array_replace($originaFormValues, $profile);
        $form->formValues($formValues);
        trace($formValues, 'Form Values');

        // Save some original data in the attributes of the connection for later API calls.
        $attributes = [];
        $attributes[$this->getProviderKey()] = [
            'AccessToken' => $accessToken,
            'RefreshToken' => $refreshToken,
            'Profile' => $profile
        ];
        $form->setFormValue('Attributes', $attributes);
        $form->addHidden('stashID', $stashID);
        $sender->EventArguments['Profile'] = $profile;
        $sender->EventArguments['Form'] = $form;

        $this->log('Base Connect Data Before OAuth Event', ['profile' => $profile, 'form' => $form]);

        // Throw an event so that other plugins can add/remove stuff from the basic sso.
        $sender->fireEvent('OAuth');

        SpamModel::disabled(true);
        $sender->setData('Trusted', true);
        $sender->setData('Verified', true);
    }

    /**
     * Request access token from provider.
     *
     * @param string $code code returned from initial handshake with provider.
     * @param bool $refresh if we are using the stored RefreshToken to request a new AccessToken
     * @return mixed Result of the API call to the provider, usually JSON.
     */
    public function requestAccessToken($code, $refresh=false) {
        $provider = $this->provider();
        $uri = val('TokenUrl', $provider);

        //When requesting the AccessToken using the RefreshToken the params are different.
        if ($refresh) {
            $defaultParams = [
                'refresh_token' => $code,
                'grant_type' => 'refresh_token'
            ];
        } else {
            $defaultParams = [
                'code' => $code,
                'client_id' => val('AssociationKey', $provider),
                'redirect_uri' => url('/entry/'. $this->getProviderKey(), true),
                'client_secret' => val('AssociationSecret', $provider),
                'grant_type' => 'authorization_code',
                'scope' => val('AcceptedScope', $provider)
            ];
        }

        // Merge any parameters inherited parameters, remove any empty parameters before sending them in the request.
        $post = array_filter(array_merge($defaultParams, $this->requestAccessTokenParams));

        $this->log('Before calling API to request access token', ['requestAccessToken' => ['targetURI' => $uri, 'post' => $post]]);

        $this->accessTokenResponse = $this->api($uri, 'POST', $post, $this->getAccessTokenRequestOptions());

        return $this->accessTokenResponse;
    }

    /**
     *   Allow the admin to input the keys that their service uses to send data.
     *
     * @param array $rawProfile profile as it is returned from the provider.
     *
     * @return array Profile array transformed by child class or as is.
     */
    public function translateProfileResults($rawProfile = []) {
        $provider = $this->provider();
        $email = val('ProfileKeyEmail', $provider, 'email');
        $translatedKeys = [
            val('ProfileKeyEmail', $provider, 'email') => 'Email',
            val('ProfileKeyPhoto', $provider, 'picture') => 'Photo',
            val('ProfileKeyName', $provider, 'displayname') => 'Name',
            val('ProfileKeyFullName', $provider, 'name') => 'FullName',
            val('ProfileKeyUniqueID', $provider, 'user_id') => 'UniqueID'
        ];

        $profile = self::translateArrayMulti($rawProfile, $translatedKeys, true);

        $profile['Provider'] = $this->providerKey;

        return $profile;
    }


    /**
     * Get profile data from authentication provider through API.
     *
     * @return array User profile from provider.
     */
    public function getProfile() {
        $provider = $this->provider();
        $uri = $this->requireVal('ProfileUrl', $provider, 'provider');
        $defaultParams = [];
        $defaultOptions = [];

        // Send the Access Token as an Authorization header, depending on the client workflow.
        if (val('BearerToken', $provider)) {
            $defaultOptions = [
                'Authorization-Header-Message' => 'Bearer '.$this->accessToken()
            ];
        }

        // Merge with any other Header options being set by child classes.
        $requestOptions = array_filter(array_merge($defaultOptions, $this->getProfileRequestOptions()));

        // Send the Access Token is a Get parameter, depending on the client workflow.
        if (!val('BearerToken', $provider)) {
            $defaultParams = [
                'access_token' => $this->accessToken()
            ];
        }
        // Merge any inherited parameters and remove any empty parameters before sending them in the request.
        $requestParams = array_filter(array_merge($defaultParams, $this->requestProfileParams));

        // Request the profile from the Authentication Provider
        $rawProfile = $this->api($uri, 'GET', $requestParams, $requestOptions);

        // Translate the keys of the profile sent to match the keys we are looking for.
        $profile = $this->translateProfileResults($rawProfile);

        // Log the results when troubleshooting.
        $this->log('getProfile API call', ['ProfileUrl' => $uri, 'Params' => $requestParams, 'RawProfile' => $rawProfile, 'Profile' => $profile]);

        return $profile;
    }



    /** ------------------- Buttons, linking --------------------- */

    /**
     * Redirect to provider's signin page if this is the default behaviour.
     *
     * @param EntryController $sender.
     * @param EntryController $args.
     *
     * @return mixed|bool Return null if not configured.
     */
    public function entryController_overrideSignIn_handler($sender, $args) {
        $provider = $args['DefaultProvider'];
        if (val('AuthenticationSchemeAlias', $provider) != $this->getProviderKey() || !$this->isConfigured()) {
            return;
        }

        $url = $this->authorizeUri(['target' => $args['Target']]);
        $args['DefaultProvider']['SignInUrl'] = $url;
    }


    /**
     * Inject a sign-in icon into the ME menu.
     *
     * @param Gdn_Controller $sender.
     * @param Gdn_Controller $args.
     */
    public function base_beforeSignInButton_handler($sender, $args) {
        if (!$this->isConfigured() || $this->isDefault()) {
            return;
        }

        echo ' '.$this->signInButton('icon').' ';
    }


    /**
     * Inject sign-in button into the sign in page.
     *
     * @param EntryController $sender.
     * @param EntryController $args.
     *
     * @return mixed|bool Return null if not configured
     */
    public function entryController_signIn_handler($sender, $args) {
        if (!$this->isConfigured()) {
            return;
        }
        if (isset($sender->Data['Methods'])) {
            // Add the sign in button method to the controller.
            $method = [
                'Name' => $this->getProviderKey(),
                'SignInHtml' => $this->signInButton()
            ];

            $sender->Data['Methods'][] = $method;
        }
    }


    /**
     * Create signup button specific to this plugin.
     *
     * @param string $type Either button or icon to be output.
     *
     * @return string Resulting HTML element (button).
     */
    public function signInButton($type = 'button') {
        $target = Gdn::request()->post('Target', Gdn::request()->get('Target', url('', '/')));
        $url = $this->authorizeUri(['target' => $target]);
        $providerName = $this->provider['Name'] ?? 'OAuth';
        $linkLabel = sprintf(t('Sign in with %s'), $providerName);
        $result = socialSignInButton($providerName, $url, $type, ['rel' => 'nofollow', 'class' => 'default', 'title' => $linkLabel]);
        return $result;
    }

    /**
     * Insert css file for generic styling of signin button/icon.
     *
     * @param AssetModel $sender.
     * @param AssetModel $args.
     */
    public function assetModel_styleCss_handler($sender, $args) {
        $sender->addCssFile('oauth2.css', 'plugins/oauth2');
    }

    /** ------------------- Helper functions --------------------- */

    /**
     * Extract values from arrays.
     *
     * @param string $key Needle.
     * @param array $arr Haystack.
     * @param string $context Context to make error messages clearer.
     *
     * @return mixed Extracted value from array.
     *
     * @throws Exception.
     */
    public static function requireVal($key, $arr, $context = null) {
        $result = val($key, $arr);
        if (!$result) {
            throw new \Exception("Key {$key} missing from {$context} collection.", 500);
        }
        return $result;
    }


    /**
     * Allow admins to use dot notation to map values from multi-dimensional arrays.
     *
     * @param array $array The array from which we will extract values.
     * @param array $mappings The map of keys where we will find the values in $array and the new keys we will assign to them.
     * @param bool|false $addRemaining Tack on all the unmapped values of $array.
     * @return array An array with the keys passed in $mappings with corresponding values from $array and all the remaining values of $array.
     */
    public static function translateArrayMulti($array, $mappings, $addRemaining = false) {
        $array = (array)$array;
        $result = [];
        foreach ($mappings as $index => $value) {
            if (is_numeric($index)) {
                $key = $value;
                $newKey = $value;
            } else {
                $key = $index;
                $newKey = $value;
            }

            if ($newKey === null) {
                unset($array[$key]);
                continue;
            }

            if (valr($key, $array)) {
                $result[$newKey] = valr($key, $array);
                if (isset($array[$key])) {
                    unset($array[$key]);
                }
            } else {
                $result[$newKey] = null;
            }
        }

        if ($addRemaining) {
            foreach ($array as $key => $value) {
                if (!isset($result[$key])) {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * Inject dependencies without affecting subclass constructors.
     *
     * Note that injecting the container is an anti-pattern, but is a trade-off in this case since this plugin is
     * constructed every request.
     *
     * @param SsoUtils $ssoUtils Used to generate SSO tokens.
     * @param SessionModel $sessionModel Used to stash keys.
     * @param \Psr\Container\ContainerInterface $container Used to get dependencies that are rarely used.
     */
    public function setDependencies(
        SsoUtils $ssoUtils,
        SessionModel $sessionModel,
        \Garden\Container\Container $container
    ) {
        $this->ssoUtils = $ssoUtils;
        $this->sessionModel = $sessionModel;
        $this->container = $container;

        $this->container->setInstance(static::containerKey($this->providerKey), $this);
    }

    /**
     * When DB_Logger is turned on, log SSO data.
     *
     * @param $message
     * @param $data
     */
    public function log($message, $data) {
        if (c('Vanilla.SSO.Debug')) {
            Logger::event(
                'sso_logging',
                Logger::INFO,
                $message,
                $data
            );
        }
    }

    /**
     * Encode an array into a state parameter.
     *
     * Some OAuth servers will mess up double URL encoding so use something safe here.
     *
     * @param array $state The state to encode.
     * @return string Returns the encoded state.
     */
    protected function encodeState(array $state): string {
        return base64_encode(json_encode($state));
    }

    /**
     * Decode a state string into its original array.
     *
     * If the state cannot be decoded this method returns an empty string so the caller should be responsible for
     * propagating any errors.
     *
     * @param string $state The state to decode.
     * @return array Returns the decoded state.
     */
    protected function decodeState(string $state): array {
        if (empty($state)) {
            return [];
        } else {
            $r = json_decode(base64_decode($state), true);
            if (is_array($r)) {
                return $r;
            } else {
                return [];
            }
        }
    }

    /**
     * Exchange an OAuth access token for a Vanilla access token.
     *
     * @param TokensApiController $sender
     * @param array $body
     * @return \Garden\Web\Data
     */
    final public function tokensApiController_post_oauth(TokensApiController $sender, array $body): \Garden\Web\Data {
        $sender->permission(Permissions::BAN_CSRF);

        $in = $sender->schema([
            'clientID:s',
            'oauthAccessToken:s',
        ], 'in');

        $valid = $in->validate($body);

        // Look up the specific addon that owns this client ID.
        $instance = $this->getInstanceFromClientID($valid['clientID']);

        try {
            $result = $instance->issueAccessToken($valid['clientID'], $valid['oauthAccessToken']);
        } catch (ContainerExceptionInterface $ex) {
            throw new ServerException("There was an error getting the OAuth client instance.");
        }

        return new \Garden\Web\Data($result);
    }

    /**
     * Connect the user payload with a user.
     *
     * @param array $payload The user profile.
     * @return int
     * @throws ClientException Throws an exception if the user cannot be connected for some reason.
     * @throws Garden\Schema\ValidationException Throws an exception if the payload doesn't contain the required fields.
     */
    final private function sso(array $payload): int {
        unset($payload['UserID']); // safety precaution due to Gdn_UserModel::connect() behaviour

        /* @var \UserModel $userModel */
        $userModel = $this->container->get(\UserModel::class);

        $userID = $userModel->connect(
            $payload['UniqueID'] ?? '',
            static::PROVIDER_KEY,
            $payload,
            ['SyncExisting' => false]
        );

        if (!$userID) {
            \Vanilla\Utility\ModelUtils::validationResultToValidationException($userModel, $this->container->get(\Gdn_Locale::class));
        }

        return (int)$userID;
    }

    /**
     * Get the specific plugin instance from a client ID.
     *
     * This class stores the client ID in the attributes field, which makes it quite difficult to look up. Furthermore,
     * There is no information in the `Gdn_AuthenticationProvider` table that lets us know which class controlls that
     * row.
     *
     * To get around that this method loops through all of the providers to find a match and then gets the instance from
     * the container.
     *
     * @param string $clientID
     * @return $this
     * @throws \Garden\Container\ContainerException Throws an exception when the instance wasn't properly registered in the container.
     */
    final public function getInstanceFromClientID(string $clientID): self {
        $type = $this->getProviderTypeFromClientID($clientID);
        $instance = $this->container->get(static::containerKey($type));

        return $instance;
    }

    /**
     * Get the provider type from a client ID.
     *
     * @param string $clientID
     * @return string
     * @throws NotFoundException Throws an exception if there is no provider with that client ID.
     */
    final private function getProviderTypeFromClientID(string $clientID): string {
        $key = "authenticationPoviderType.clientID.$clientID";

        $cachedType = Gdn::cache()->get($key);

        if ($cachedType === Gdn_Cache::CACHEOP_FAILURE) {
            $providers = Gdn_AuthenticationProviderModel::getWhereStatic();

            foreach ($providers as $provider) {
                if ($clientID === $provider['AssociationKey'] ?? '') {
                    $cachedType = $provider['AuthenticationSchemeAlias'];
                    Gdn::cache()->store($key, $cachedType, [Gdn_Cache::FEATURE_EXPIRY => 300]);
                    return $cachedType;
                }
            }

            throw new NotFoundException("An OAuth client with ID \"$clientID\" could not be found.");
        }
        return $cachedType;
    }

    /**
     * Exchange an OAuth access token for a client ID.
     *
     * @param string $clientID The OAuth client ID (AssociationKey in the db).
     * @param string $oauthAccessToken A valid access token for calling the OAuth server.
     * @return array Returns an array with the access token and expiry date.
     * @throws \Garden\Container\ContainerException Throws an exception if the addon instance was improperly registered.
     */
    final protected function issueAccessToken(string $clientID, string $oauthAccessToken): array {
        if ($clientID !== $this->provider()['AssociationKey'] ?? null) {
            throw new ClientException('Invalid client ID.', 422);
        }

        if (!$this->isConfigured()) {
            throw new ServerException('The OAuth client has not been configured.', 500);
        }

        if (!$this->isActive()) {
            throw new ServerException('The OAuth client is not active', 500);
        }

        if (!($this->provider()['AllowAccessTokens'] ?? false)) {
            throw new ServerException('The OAuth client is not allowed to issue access tokens.', 500);
        }

        $this->accessToken($oauthAccessToken);
        try {
            $profile = $this->getProfile();
        } catch (\Exception $ex) {
            throw new \Garden\Web\Exception\ForbiddenException($ex->getMessage());
        }

        $userID = $this->sso($profile);

        /* @var AccessTokenModel $tokenModel */
        $tokenModel = $this->container->get(AccessTokenModel::class);

        $expires = new DateTimeImmutable('+24 hours');
        $token = $tokenModel->issue($userID, $expires, 'tokens/oauth');
        $result = [
            'accessToken' => $token,
            'dateExpires' => $expires,
        ];
        return $result;
    }
}
