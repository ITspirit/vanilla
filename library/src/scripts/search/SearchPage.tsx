/**
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

import { SearchPageResults } from "@library/search/SearchPageResults";
import { LoadStatus } from "@library/@types/api/core";
import SearchBar from "@library/features/search/SearchBar";
import { searchBarClasses } from "@library/features/search/searchBarStyles";
import SearchOption from "@library/features/search/SearchOption";
import { ButtonTypes } from "@library/forms/buttonTypes";
import TitleBar from "@library/headers/TitleBar";
import Container from "@library/layout/components/Container";
import { Devices, useDevice } from "@library/layout/DeviceContext";
import Drawer from "@library/layout/drawer/Drawer";
import { PageHeading } from "@library/layout/PageHeading";
import { pageTitleClasses } from "@library/layout/pageTitleStyles";
import PanelLayout, {Panel, PanelWidget} from "@library/layout/PanelLayout";
import DocumentTitle from "@library/routing/DocumentTitle";
import QueryString from "@library/routing/QueryString";
import { SearchInFilter } from "@library/search/SearchInFilter";
import { typographyClasses } from "@library/styles/typographyStyles";
import { t } from "@library/utility/appUtils";
import Banner from "@vanilla/library/src/scripts/banner/Banner";
import { useSearchForm } from "@vanilla/library/src/scripts/search/SearchFormContext";
import { useLastValue } from "@vanilla/react-utils";
import classNames from "classnames";
import debounce from "lodash/debounce";
import qs from "qs";
import * as React from "react";
import { useCallback, useEffect } from "react";
import { useLocation } from "react-router";

// This new page must have our base reset in place.
import "@library/theming/reset";
import {SearchPagination} from "@library/search/SearchPagination";
import {SortAndPaginationInfo} from "@library/search/SortAndPaginationInfo";
import {dummyPaginationData, dummySortData} from "@library/search/SearchMiscellaneousComponents.story";

interface IProps {
    placeholder?: string;
}

function SearchPage(props: IProps) {
    const {
        form,
        updateForm,
        search,
        results,
        getDomains,
        getCurrentDomain,
        getDefaultFormValues,
    } = useSearchForm<{}>();
    const device = useDevice();
    const isMobile = device === Devices.MOBILE || device === Devices.XS;
    const classes = pageTitleClasses();
    useInitialQueryParamSync();

    const debouncedSearch = useCallback(
        debounce(() => {
            search();
        }, 800),
        [search],
    );

    const { domain } = form;
    const lastDomain = useLastValue(form.domain);
    useEffect(() => {
        if (lastDomain && domain !== lastDomain) {
            search();
        }
    }, [lastDomain, domain, search]);

    const currentDomain = getCurrentDomain();
    let currentFilter = <currentDomain.PanelComponent />;

    return (
        <DocumentTitle title={form.query ? form.query : t("Search Results")}>
            <TitleBar title={t("Search")} />
            <Banner isContentBanner />
            <Container>
                <QueryString value={{ ...form, initialized: undefined }} defaults={getDefaultFormValues()} />
                <PanelLayout
                    className="hasLargePadding"
                    middleTop={
                        <>
                            <PanelWidget>
                                <PageHeading
                                    className={classNames(
                                        "searchBar-heading",
                                        searchBarClasses().heading,
                                        classes.smallBackLink,
                                    )}
                                    headingClassName={classNames(typographyClasses().pageTitle)}
                                    title={"Search"}
                                    includeBackLink={true}
                                    isCompactHeading={true}
                                />
                                <SearchBar
                                    placeholder={props.placeholder}
                                    onChange={newQuery => updateForm({ query: newQuery })}
                                    value={form.query}
                                    onSearch={debouncedSearch}
                                    isLoading={results.status === LoadStatus.LOADING}
                                    optionComponent={SearchOption}
                                    triggerSearchOnClear={true}
                                    titleAsComponent={t("Search")}
                                    handleOnKeyDown={event => {
                                        if (event.key === "Enter") {
                                            debouncedSearch();
                                        }
                                    }}
                                    disableAutocomplete={true}
                                    buttonBaseClass={ButtonTypes.PRIMARY}
                                    needsPageTitle={false}
                                />
                                <SortAndPaginationInfo paginationInfo={dummyPaginationData} sort={dummySortData} />
                            </PanelWidget>
                            <PanelWidget>
                                <SearchInFilter
                                    setData={newDomain => {
                                        updateForm({ domain: newDomain });
                                    }}
                                    activeItem={form.domain}
                                    filters={getDomains().map(domain => {
                                        return {
                                            label: domain.name,
                                            icon: domain.icon,
                                            data: domain.key,
                                        };
                                    })}
                                />
                            </PanelWidget>
                            {isMobile && (
                                <PanelWidget>
                                    <Drawer title={t("Filter Results")}>{currentFilter}</Drawer>
                                </PanelWidget>
                            )}
                        </>
                    }
                    middleBottom={<>
                        <SearchPageResults />
                        <SearchPagination/>
                    </>}
                    rightTop={!isMobile && <PanelWidget>{currentFilter}</PanelWidget>}
                />
            </Container>
        </DocumentTitle>
    );
}

function useInitialQueryParamSync() {
    const { updateForm, form, search } = useSearchForm();
    const location = useLocation();

    useEffect(() => {
        const { search: browserQuery } = location;
        const queryForm = qs.parse(browserQuery.replace(/^\?/, ""));

        for (const [key, value] of Object.entries(queryForm)) {
            if (value === "true") {
                queryForm[key] = true;
            }

            if (value === "false") {
                queryForm[key] = false;
            }

            if (typeof value === "string" && Number.isInteger(parseInt(value, 10))) {
                queryForm[key] = parseInt(value, 10);
            }
        }

        queryForm.initialized = true;

        updateForm(queryForm);
        // Only for first initialization.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const { initialized } = form;
    const lastinitialized = useLastValue(form.initialized);
    useEffect(() => {
        if (!lastinitialized && initialized) {
            search();
        }
    }, [search, lastinitialized, initialized]);
}

export default SearchPage;