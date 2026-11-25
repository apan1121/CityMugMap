<template>
    <main class="city-mug-page">
        <header class="site-header">
            <div class="site-brand">
                <span class="site-brand-mark">
                    <img
                        :src="siteLogoUrl"
                        alt="City Mug Map logo"
                        class="site-brand-logo"
                    >
                </span>
                <div class="site-brand-text">
                    <strong>City Mug Map</strong>
                    <span>Starbucks collection</span>
                </div>
            </div>

            <div class="site-header-actions">
                <div class="view-switch site-switch" role="group" aria-label="檢視模式">
                    <button
                        type="button"
                        class="switch-button"
                        :class="{ 'is-active': activeView === 'map' }"
                        :aria-pressed="activeView === 'map'"
                        @click="activeView = 'map'"
                    >
                        地圖模式
                    </button>
                    <button
                        type="button"
                        class="switch-button"
                        :class="{ 'is-active': activeView === 'list' }"
                        :aria-pressed="activeView === 'list'"
                        @click="activeView = 'list'"
                    >
                        列表模式
                    </button>
                </div>

            </div>
        </header>

        <header class="page-header">
            <div class="page-header-main">
                <div class="page-heading-row">
                    <div>
                        <h1 class="page-title">城市杯收藏地圖</h1>
                        <p class="page-desc">
                            Starbucks 城市杯的地理索引與收藏資料。
                        </p>
                    </div>

                    <p class="page-stats" aria-label="收藏統計">
                        {{ filteredItems.length }} 收藏 <span aria-hidden="true">·</span>
                        {{ cityCount }} 城市 <span aria-hidden="true">·</span>
                        {{ countryCount }} 國家
                    </p>
                </div>
                <p v-if="isLoading" class="data-status" role="status" aria-live="polite">
                    正在載入收藏資料…
                </p>
            </div>

            <div class="page-toolbar">
                <div class="search-box">
                    <label for="collection-search" class="search-label">搜尋收藏</label>
                    <div class="search-control">
                    <input
                        id="collection-search"
                        v-model.trim="searchKeyword"
                        type="text"
                        class="search-input"
                        placeholder="搜尋城市、國家、杯子名稱"
                        aria-describedby="search-status"
                        @keydown.escape="clearSearch"
                    >
                        <button
                            v-if="searchKeyword"
                            type="button"
                            class="search-clear"
                            aria-label="清除搜尋"
                            @click="clearSearch"
                        >
                            ×
                        </button>
                    </div>
                    <p id="search-status" class="search-status" aria-live="polite">{{ resultSummary }}</p>
                </div>

            <div v-if="activeView === 'map'" class="toolbar-actions" aria-label="地圖操作">
                    <button type="button" class="toolbar-button" @click="fitAllMarkers">
                        縮放至全部收藏
                    </button>
                    <button type="button" class="toolbar-button is-primary" @click="locateUser">
                        定位目前位置
                    </button>
                    <p class="location-status" role="status" aria-live="polite">{{ locationStatus }}</p>
                </div>

            </div>
        </header>

        <section v-show="activeView === 'map'" class="map-stage">
            <h2 class="section-title sr-only">城市杯地圖</h2>
            <div class="map-workspace">
                <div class="map-shell">
                    <div ref="mapBox" class="leaflet-map"></div>
                </div>

                <aside class="collection-index" aria-labelledby="map-index-title">
                    <div class="index-head">
                        <div>
                            <p class="section-kicker">Collection index</p>
                            <h2 id="map-index-title" class="section-title">收藏索引</h2>
                        </div>
                        <span class="index-count">視野內 {{ visibleItems.length }} 件</span>
                    </div>

                    <div v-if="visibleItems.length" class="index-list">
                        <button
                            v-for="item in visibleItems"
                            :key="`index-${item.id}`"
                            type="button"
                            class="index-row"
                            :class="{ 'is-active': highlightedId === item.id }"
                            :aria-label="itemAccessibleLabel(item)"
                            @click="openItemModal(item.id)"
                        >
                            <span class="index-thumb">
                                <img
                                    :src="itemImageUrl(item)"
                                    :alt="`${indexDisplayName(item)} 縮圖`"
                                    loading="lazy"
                                    @error="onImgError"
                                >
                            </span>
                            <span class="index-copy">
                                <strong>{{ indexDisplayName(item) }}</strong>
                                <span>{{ itemTypeLabel(item) }} · {{ indexLocation(item) }}</span>
                                <small>{{ item.id }}</small>
                            </span>
                        </button>
                    </div>
                    <p v-else class="empty-state" role="status" aria-live="polite">
                        目前地圖視野內沒有收藏，請拖曳或縮放地圖。
                    </p>
                </aside>
            </div>
            <div v-if="loadError" class="state-panel state-panel--error" role="alert">
                <strong>收藏資料無法載入</strong>
                <span>{{ loadError }}</span>
                <button type="button" class="state-panel-action" @click="reloadItems">重新載入</button>
            </div>
        </section>

        <section v-if="activeView === 'list'" class="list-section" aria-labelledby="collection-list-title">
            <div class="list-head">
                <div>
                    <p class="section-kicker">Collection</p>
                    <h2 id="collection-list-title" class="section-title">收藏列表</h2>
                </div>
            </div>

            <div v-if="filteredItems.length" class="list-grid">
                <button
                    v-for="item in filteredItems"
                    :key="item.id"
                    type="button"
                    class="mug-card"
                    :aria-label="itemAccessibleLabel(item)"
                    @click="openItemModal(item.id)"
                >
                    <div class="mug-card-image-box">
                        <img
                            :src="itemImageUrl(item)"
                            :alt="itemImageAlt(item)"
                            class="mug-card-image"
                            loading="lazy"
                            @error="onImgError"
                        >
                        <div class="mug-image-fallback" hidden aria-hidden="true">
                            <span class="mug-image-fallback-icon">☕</span>
                            <span>圖片無法載入</span>
                        </div>
                    </div>
                    <div class="mug-card-body">
                        <p class="mug-card-location">{{ itemTypeLabel(item) }} · {{ indexLocation(item) }}</p>
                        <h3 class="mug-card-title">{{ indexDisplayName(item) }}</h3>
                        <p class="mug-card-desc">{{ item.description }}</p>
                        <div class="mug-card-footer">
                            <span>{{ item.id }}</span>
                            <span>{{ item.created_at }}</span>
                        </div>
                    </div>
                </button>
            </div>
            <p v-else class="empty-state" role="status" aria-live="polite">
                找不到符合「{{ searchKeyword }}」的收藏，請嘗試其他關鍵字。
            </p>
        </section>

        <div
            v-if="modalItem"
            class="mug-modal-overlay"
            @click.self="closeModal"
        >
            <div class="mug-modal" role="dialog" aria-modal="true" aria-labelledby="mug-modal-title">
                <button
                    ref="modalCloseBtn"
                    type="button"
                    class="mug-modal-close"
                    aria-label="關閉城市杯詳細資料"
                    @click="closeModal"
                >
                    ×
                </button>

                <div class="mug-modal-media">
                    <img
                        :src="itemImageUrl(modalItem)"
                        :alt="itemImageAlt(modalItem)"
                        class="mug-modal-image"
                        @error="onImgError"
                    >
                    <div class="mug-image-fallback mug-image-fallback--modal" hidden aria-hidden="true">
                        <span class="mug-image-fallback-icon">☕</span>
                        <span>圖片無法載入</span>
                    </div>
                    <div v-if="modalGroup.length > 1" class="mug-modal-thumbs">
                        <button
                            v-for="item in modalGroup"
                            :key="item.id"
                            type="button"
                            class="mug-modal-thumb"
                            :class="{ 'is-active': item.id === modalId }"
                            @click="switchModalItem(item.id)"
                        >
                            <img
                                :src="itemImageUrl(item)"
                                :alt="item.display_name"
                                class="mug-modal-thumb-img"
                                loading="lazy"
                            >
                        </button>
                    </div>
                </div>

                <div class="mug-modal-content">
                    <p class="mug-modal-id">{{ modalItem.id }}</p>
                    <h2 id="mug-modal-title" class="mug-modal-title">{{ modalItem.display_name }}</h2>
                    <p class="mug-modal-location">{{ itemTypeLabel(modalItem) }} · {{ indexLocation(modalItem) }}</p>
                    <p class="mug-modal-desc">{{ modalItem.description }}</p>

                    <section
                        v-if="modalCityMeta && citySummaryText(modalCityMeta)"
                        class="mug-modal-place-story"
                        aria-labelledby="mug-modal-place-title"
                    >
                        <div class="mug-modal-place-story-head">
                            <span class="meta-label">Place notes</span>
                            <span class="mug-modal-place-source">{{ citySummarySourceLabel(modalCityMeta) }}</span>
                        </div>
                        <h3 id="mug-modal-place-title">{{ modalCityMeta.city }} 地點介紹</h3>
                        <p>{{ citySummaryText(modalCityMeta) }}</p>
                        <a
                            v-if="citySummaryUrl(modalCityMeta)"
                            class="mug-modal-place-source-link"
                            :href="citySummaryUrl(modalCityMeta)"
                            target="_blank"
                            rel="noreferrer noopener"
                        >
                            查看地圖 <span aria-hidden="true">↗</span>
                        </a>
                        <details
                            v-if="citySummarySources(modalCityMeta).length"
                            class="mug-modal-summary-sources"
                        >
                            <summary>
                                資料來源（{{ citySummarySources(modalCityMeta).length }}）
                            </summary>
                            <ul>
                                <li v-for="(source, sourceIndex) in citySummarySources(modalCityMeta)" :key="source.url">
                                    <a
                                        :href="source.url"
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        {{ citySummarySourceName(source, sourceIndex) }}
                                        <span aria-hidden="true">↗</span>
                                    </a>
                                </li>
                            </ul>
                        </details>
                    </section>
                    <p v-else-if="modalCityMetaStatus" class="mug-modal-place-status" role="status" aria-live="polite">
                        {{ modalCityMetaStatus }}
                    </p>

                    <div class="mug-modal-geo">
                        <div class="mug-modal-geo-head">
                            <span class="meta-label">City Boundary</span>
                            <strong>{{ modalItem.city }} GeoJSON</strong>
                        </div>

                        <div class="mug-modal-geo-canvas">
                            <div
                                v-if="modalGeoStatus === 'ready'"
                                ref="modalMapBox"
                                class="mug-modal-geo-map"
                            ></div>
                            <div v-else class="mug-modal-geo-empty">
                                {{ modalGeoStatus }}
                            </div>
                        </div>
                    </div>

                    <div class="mug-modal-meta">
                        <div class="mug-modal-meta-item">
                            <span class="meta-label">Created</span>
                            <strong>{{ modalItem.created_at }}</strong>
                        </div>
                        <div class="mug-modal-meta-item">
                            <span class="meta-label">Coords</span>
                            <strong>{{ modalItem.lat }}, {{ modalItem.lng }}</strong>
                        </div>
                    </div>

                    <div class="mug-modal-links">
                        <button
                            v-if="modalItem"
                            type="button"
                            class="mug-modal-link"
                            @click="openJsonViewer(itemMetaUrl(modalItem), 'meta.json')"
                        >
                            查看 meta.json
                        </button>
                        <button
                            v-if="modalItem"
                            type="button"
                            class="mug-modal-link"
                            @click="openJsonViewer(itemBoundaryUrl(modalItem), 'boundary.geojson')"
                        >
                            查看 boundary.geojson
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="jsonViewerVisible"
            class="json-viewer-overlay"
            @click.self="closeJsonViewer"
        >
            <div class="json-viewer-modal" role="dialog" aria-modal="true" :aria-label="jsonViewerTitle">
                <div class="json-viewer-header">
                    <span class="json-viewer-title">{{ jsonViewerTitle }}</span>
                    <button
                        ref="jsonViewerCloseBtn"
                        type="button"
                        class="json-viewer-close"
                        aria-label="關閉 JSON 檢視器"
                        @click="closeJsonViewer"
                    >×</button>
                </div>
                <pre class="json-viewer-body">{{ jsonViewerContent }}</pre>
            </div>
        </div>
    </main>
</template>

<script>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: './dist/img/map/marker-icon.png',
    iconRetinaUrl: './dist/img/map/marker-icon-2x.png',
    shadowUrl: './dist/img/map/marker-shadow.png',
});
import { disableBodyScroll, enableBodyScroll } from 'body-scroll-lock';
import { linkRegister } from 'lib/common/util';

export default {
    name: 'MainPage',
    data(){
        return {
            activeView: 'map',
            searchKeyword: '',
            debouncedKeyword: '',
            siteLogoUrl: './dist/img/coffee_logo.png',
            items: [],
            modalId: '',
            modalGeoStatus: '載入 GeoJSON 中...',
            loadError: '',
            isLoading: true,
            map: null,
            mapReady: false,
            markerLayerGroup: null,
            boundaryLayer: null,
            hoverBoundaryLayer: null,
            hoverLabelMarker: null,
            modalMap: null,
            modalBoundaryLayer: null,
            currentLocationMarker: null,
            currentLocationCircle: null,
            highlightedId: '',
            locationStatus: '尚未定位',
            geoJsonCache: {},
            resizeHandler: null,
            jsonViewerVisible: false,
            jsonViewerTitle: '',
            jsonViewerContent: '',
            modalGroup: [],
            mapViewport: null,
            cityMetaCache: {},
            modalCityMeta: null,
            modalCityMetaStatus: '載入地點介紹中…',
        };
    },
    computed: {
        filteredItems(){
            const keyword = this.debouncedKeyword.toLowerCase();
            if (!keyword) {
                return this.items;
            }

            return this.items.filter((item) => {
                const haystacks = [
                    item.display_name,
                    item.city,
                    item.country,
                    item.description,
                ];

                return haystacks.some((field) => String(field || '').toLowerCase().includes(keyword));
            });
        },
        modalItem(){
            return this.items.find((item) => item.id === this.modalId) || null;
        },
        cityCount(){
            return new Set(this.filteredItems.map((item) => item.city_key)).size;
        },
        countryCount(){
            return new Set(this.filteredItems.map((item) => item.country)).size;
        },
        resultSummary(){
            const count = this.filteredItems.length;
            return this.debouncedKeyword
                ? `搜尋結果 ${count} 筆`
                : `顯示全部 ${count} 筆收藏`;
        },
        sortedFilteredItems(){
            return this.sortItemsByCreatedAtDesc(this.filteredItems);
        },
        visibleItems(){
            if (!this.mapViewport) {
                return this.sortedFilteredItems;
            }

            return this.sortedFilteredItems.filter((item) => {
                const lat = Number(item.lat);
                const lng = Number(item.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                    return false;
                }

                const { north, south, east, west } = this.mapViewport;
                const withinLatitude = lat >= south && lat <= north;
                const withinLongitude = west <= east
                    ? lng >= west && lng <= east
                    : lng >= west || lng <= east;
                return withinLatitude && withinLongitude;
            });
        },
    },
    watch: {
        searchKeyword(val){
            clearTimeout(this._keywordTimer);
            this._keywordTimer = setTimeout(() => {
                this.debouncedKeyword = val;
            }, 200);
        },
        filteredItems(){
            this.renderMapItems();
        },
        activeView(nextValue){
            if (nextValue !== 'map') {
                return;
            }

            this.$nextTick(() => {
                if (this.map) {
                    this.map.invalidateSize();
                }
                this.fitAllMarkers();
            });
        },
    },
    async mounted(){
        this.registerPageCss();
        await this.loadItems();
        await this.initLeafletMap();
        this.renderMapItems();
        this._escHandler = (e) => {
            if (e.key !== 'Escape') {
                return;
            }
            if (this.jsonViewerVisible) {
                this.closeJsonViewer();
            } else if (this.modalId) {
                this.closeModal();
            }
        };
        this._tabHandler = (e) => {
            if (e.key !== 'Tab' || (!this.modalId && !this.jsonViewerVisible)) {
                return;
            }
            const modalSelector = this.jsonViewerVisible ? '.json-viewer-modal' : '.mug-modal';
            const modal = this.$el.querySelector(modalSelector);
            if (!modal) {
                return;
            }
            const focusable = Array.from(modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'));
            if (focusable.length === 0) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', this._escHandler);
        document.addEventListener('keydown', this._tabHandler);
    },
    beforeUnmount(){
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
        }
        document.removeEventListener('keydown', this._escHandler);
        document.removeEventListener('keydown', this._tabHandler);
        clearTimeout(this._keywordTimer);
        clearTimeout(this._hoverTimer);

        this.destroyModalMap();

        if (this.map) {
            this.map.remove();
        }
    },
    methods: {
        itemTypeLabel(item){
            if (!item) {
                return '';
            }
            if (item.type === 'country') {
                return 'COUNTRY';
            }
            if (item.type === 'region') {
                return 'REGION';
            }
            if (item.type === 'store') {
                return 'STORE';
            }
            return 'CITY';
        },
        sortItemsByCreatedAtDesc(items){
            return [...items].sort((left, right) => {
                const leftTime = Date.parse(left.created_at || '') || 0;
                const rightTime = Date.parse(right.created_at || '') || 0;
                return rightTime - leftTime || String(right.id).localeCompare(String(left.id));
            });
        },
        indexDisplayName(item){
            if (!item) {
                return '';
            }
            return item.type === 'country' ? item.country : (item.display_name || item.city);
        },
        indexLocation(item){
            if (!item) {
                return '';
            }
            return item.type === 'country' ? item.country : `${item.city}, ${item.country}`;
        },
        itemImageAlt(item){
            if (!item) {
                return '城市杯圖片';
            }

            const label = this.indexDisplayName(item);
            const location = this.indexLocation(item);
            return `${label}，${this.itemTypeLabel(item)} · ${location}，收藏編號 ${item.id}`;
        },
        itemAccessibleLabel(item){
            if (!item) {
                return '';
            }

            return `開啟 ${this.indexDisplayName(item)}，${this.itemTypeLabel(item)} · ${this.indexLocation(item)}，收藏編號 ${item.id}`;
        },
        registerPageCss(){
            linkRegister.register([
                {
                    rel: 'stylesheet',
                    type: 'text/css',
                    href: 'dist/css/page/page.css',
                },
            ]);
        },
        async loadItems(){
            this.isLoading = true;
            try {
                const response = await fetch(this.withTs('./output/main.json'));
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const data = await response.json();
                this.items = Array.isArray(data) ? data : [];
                this.loadError = '';
            } catch (error) {
                console.error('Failed to load mug items.', error);
                this.items = [];
                this.loadError = '收藏資料載入失敗，請重新整理頁面再試。';
            } finally {
                this.isLoading = false;
            }
        },
        async reloadItems(){
            await this.loadItems();
            this.renderMapItems();
        },
        async initLeafletMap(){
            if (!this.$refs.mapBox) {
                return;
            }

            this.map = L.map(this.$refs.mapBox, {
                zoomControl: false,
                worldCopyJump: true,
                zoomAnimation: false,
                fadeAnimation: false,
                markerZoomAnimation: false,
                wheelPxPerZoomLevel: 1000,
            }).setView([18, 120], 3);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this.map);

            L.control.zoom({
                position: 'bottomright',
            }).addTo(this.map);

            if (L.markerClusterGroup) {
                const CLUSTER_LEVELS = [
                    { max: 5,        level: 'xs',  size: 44 },
                    { max: 10,       level: 'sm',  size: 48 },
                    { max: 15,       level: 'md',  size: 52 },
                    { max: 20,       level: 'lg',  size: 58 },
                    { max: 30,       level: 'xl',  size: 68 },
                    { max: Infinity, level: 'xxl', size: 80 },
                ];

                this.markerLayerGroup = L.markerClusterGroup({
                    maxClusterRadius: 50,
                    showCoverageOnHover: false,
                    iconCreateFunction(cluster){
                        const count = cluster.getChildCount();
                        const { level, size } = CLUSTER_LEVELS.find(({ max }) => count <= max);
                        return L.divIcon({
                            className: `mug-cluster mug-cluster--${level}`,
                            html: `<span class="mug-cluster-inner" aria-hidden="true">${count}</span>`,
                            iconSize: [size, size],
                            iconAnchor: [size / 2, size / 2],
                        });
                    },
                }).addTo(this.map);
            } else {
                this.markerLayerGroup = L.featureGroup().addTo(this.map);
            }
            this.map.on('zoomstart movestart', () => {
                this.clearHoverLabel();
                this.clearHoverBoundary();
            });
            this.map.on('zoomend moveend', () => {
                this.updateMapViewport();
                this.$nextTick(() => this.normalizeClusterAccessibility());
            });
            this.mapReady = true;
            this.updateMapViewport();
            this.resizeHandler = () => {
                if (this.map) {
                    this.map.invalidateSize();
                }
            };
            window.addEventListener('resize', this.resizeHandler);

            setTimeout(() => {
                if (this.map) {
                    this.map.invalidateSize();
                }
            }, 0);

            setTimeout(() => {
                if (this.map) {
                    this.map.invalidateSize();
                }
                this.normalizeClusterAccessibility();
            }, 300);
        },
        renderMapItems(){
            if (!this.mapReady || !this.markerLayerGroup) {
                return;
            }

            this.markerLayerGroup.clearLayers();

            // 按 city_key 分組，每個城市只放一個 marker
            const cityGroups = {};
            this.sortedFilteredItems.forEach((item) => {
                if (!cityGroups[item.city_key]) {
                    cityGroups[item.city_key] = [];
                }
                cityGroups[item.city_key].push(item);
            });

            Object.values(cityGroups).forEach((group) => {
                const rep = group[0];
                const marker = L.marker([Number(rep.lat), Number(rep.lng)], {
                    keyboard: true,
                    title: rep.display_name || rep.city || rep.country,
                    icon: L.divIcon({
                        className: 'mug-marker-hit-area',
                        html: '<span class="mug-marker-dot" aria-hidden="true"></span>',
                        iconSize: [44, 44],
                        iconAnchor: [22, 22],
                    }),
                });

                const openMarker = () => {
                    this.clearHoverLabel();
                    this.openCityModal(group);
                };

                const activateMarker = (event) => {
                    const key = event.key || (event.originalEvent && event.originalEvent.key);
                    if (key !== 'Enter' && key !== ' ') {
                        return;
                    }
                    const nativeEvent = event.originalEvent || event;
                    nativeEvent.preventDefault();
                    openMarker();
                };

                marker.on('add', () => {
                    const element = marker.getElement();
                    if (!element) {
                        return;
                    }
                    element.setAttribute('role', 'button');
                    element.setAttribute('aria-label', this.itemAccessibleLabel(rep));
                    element.addEventListener('keydown', activateMarker);
                });

                marker.on('mouseover', () => {
                    this.showHoverLabel(rep);
                    clearTimeout(this._hoverTimer);
                    this._hoverTimer = setTimeout(() => {
                        this.previewBoundary(rep.id);
                    }, 150);
                });

                marker.on('mouseout', () => {
                    clearTimeout(this._hoverTimer);
                    this.clearHoverLabel();
                    this.clearHoverBoundary();
                });

                marker.on('click', openMarker);

                this.markerLayerGroup.addLayer(marker);
            });

            if (this.activeView === 'map') {
                this.fitAllMarkers();
            }
            this.$nextTick(() => this.normalizeClusterAccessibility());
        },
        normalizeClusterAccessibility(){
            if (!this.$refs.mapBox) {
                return;
            }

            this.$refs.mapBox.querySelectorAll('.mug-cluster').forEach((element) => {
                const count = element.querySelector('.mug-cluster-inner')
                    ? element.querySelector('.mug-cluster-inner').textContent.trim()
                    : '';
                if (!count) {
                    return;
                }

                element.setAttribute('role', 'button');
                element.setAttribute('tabindex', '0');
                element.setAttribute('aria-label', `展開 ${count} 個城市杯收藏`);
                if (element.dataset.a11yBound === 'true') {
                    return;
                }

                element.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }
                    event.preventDefault();
                    element.click();
                });
                element.dataset.a11yBound = 'true';
            });
        },
        updateMapViewport(){
            if (!this.map) {
                return;
            }

            const bounds = this.map.getBounds();
            this.mapViewport = {
                north: bounds.getNorth(),
                south: bounds.getSouth(),
                east: bounds.getEast(),
                west: bounds.getWest(),
            };
        },
        fitAllMarkers(){
            if (!this.mapReady || !this.markerLayerGroup) {
                return;
            }

            if (this.markerLayerGroup.getLayers().length === 0) {
                return;
            }

            this.clearHoverLabel();
            const bounds = this.markerLayerGroup.getBounds();
            if (bounds.isValid()) {
                this.map.fitBounds(bounds.pad(0.2), {
                    animate: false,
                });
                setTimeout(() => {
                    if (this.map) {
                        this.map.invalidateSize();
                    }
                }, 50);
            }
        },
        async openCityModal(group){
            const sortedGroup = this.sortItemsByCreatedAtDesc(group);
            this.modalGroup = sortedGroup;
            await this.openItemModal(sortedGroup[0].id);
        },
        async openItemModal(itemId){
            this._lastFocusedEl = document.activeElement;
            this.modalId = itemId;
            await this.$nextTick();
            const overlay = this.$el.querySelector('.mug-modal-overlay');
            if (overlay) disableBodyScroll(overlay);
            if (this.$refs.modalCloseBtn) {
                this.$refs.modalCloseBtn.focus();
            }
            this.modalCityMeta = null;
            this.modalCityMetaStatus = '載入地點介紹中…';
            await Promise.all([
                this.loadCityMeta(this.modalItem),
                this.loadModalGeoJson(itemId),
            ]);
            await this.highlightBoundary(itemId);
        },
        async switchModalItem(itemId){
            this.modalId = itemId;
            this.modalGeoStatus = '載入 GeoJSON 中...';
            this.modalCityMeta = null;
            this.modalCityMetaStatus = '載入地點介紹中…';
            this.destroyModalMap();
            await Promise.all([
                this.loadCityMeta(this.modalItem),
                this.loadModalGeoJson(itemId),
            ]);
            await this.highlightBoundary(itemId);
        },
        closeModal(){
            const overlay = this.$el.querySelector('.mug-modal-overlay');
            if (overlay) enableBodyScroll(overlay);
            this.modalId = '';
            this.modalGroup = [];
            this.modalCityMeta = null;
            this.modalCityMetaStatus = '';
            this.modalGeoStatus = '載入 GeoJSON 中...';
            this.clearBoundaryHighlight();
            this.destroyModalMap();
            if (this._lastFocusedEl) {
                this._lastFocusedEl.focus();
                this._lastFocusedEl = null;
            }
        },
        async loadCityMeta(item){
            if (!item || item.type === 'store') {
                this.modalCityMetaStatus = '';
                return;
            }

            const metaPath = this.itemCityMetaPath(item);
            if (this.cityMetaCache[metaPath]) {
                this.modalCityMeta = this.cityMetaCache[metaPath];
                this.modalCityMetaStatus = this.citySummaryText(this.modalCityMeta)
                    ? ''
                    : '目前沒有可用的地點介紹';
                return;
            }

            try {
                const response = await fetch(this.withTs(`./${metaPath}`));
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const meta = await response.json();
                this.cityMetaCache[metaPath] = meta;
                this.modalCityMeta = meta;
                this.modalCityMetaStatus = this.citySummaryText(meta)
                    ? ''
                    : '目前沒有可用的地點介紹';
            } catch (error) {
                console.error('Failed to load city meta.', error);
                this.modalCityMeta = null;
                this.modalCityMetaStatus = '地點介紹載入失敗';
            }
        },
        citySummaryText(meta){
            if (!meta) {
                return '';
            }

            return String(meta.summary || meta.place_summary || '').trim();
        },
        citySummarySourceLabel(meta){
            const source = String(meta && (meta.summary_source || meta.place_summary_source) || '').toLowerCase();
            if (source === 'google_places' || source === 'editorialsummary' || source === 'generativesummary') {
                return 'GOOGLE PLACES';
            }
            if (source === 'openai_web_search') {
                return 'OPENAI WEB SEARCH';
            }
            return 'CITY GUIDE';
        },
        citySummaryUrl(meta){
            return meta && meta.google_maps_uri ? meta.google_maps_uri : '';
        },
        citySummarySources(meta){
            if (!meta || !Array.isArray(meta.summary_sources)) {
                return [];
            }

            return meta.summary_sources.filter((source) => source && source.url);
        },
        citySummarySourceName(source, index){
            if (source && source.title) {
                return source.title;
            }

            try {
                return new URL(source.url).hostname.replace(/^www\./, '');
            } catch (error) {
                return `來源 ${index + 1}`;
            }
        },
        async loadModalGeoJson(itemId){
            const item = this.items.find((row) => row.id === itemId);
            if (!item) {
                this.modalGeoStatus = '沒有可用的 GeoJSON';
                return;
            }

            // Store-type mugs have a precise GPS point, no city boundary
            if (item.type === 'store') {
                this.modalGeoStatus = 'ready';
                await this.$nextTick();
                this.renderModalMap(item, null);
                return;
            }

            this.modalGeoStatus = '載入 GeoJSON 中...';

            try {
                const geojson = await this.fetchGeoJson(this.itemBoundaryPath(item));
                if (!this.hasDrawableGeometry(geojson)) {
                    this.modalGeoStatus = 'GeoJSON 已載入，但沒有可繪製的邊界';
                    return;
                }
                this.modalGeoStatus = 'ready';
                await this.$nextTick();
                this.renderModalMap(item, geojson);
            } catch (error) {
                console.error('Failed to load boundary geojson.', error);
                this.modalGeoStatus = 'GeoJSON 載入失敗';
            }
        },
        async fetchGeoJson(boundaryPath){
            if (this.geoJsonCache[boundaryPath]) {
                return this.geoJsonCache[boundaryPath];
            }

            const response = await fetch(this.withTs(`./${boundaryPath}`));
            const geojson = await response.json();
            this.geoJsonCache[boundaryPath] = geojson;

            return geojson;
        },
        async highlightBoundary(itemId){
            const item = this.items.find((row) => row.id === itemId);
            if (!item || !this.mapReady) {
                return;
            }

            const geojson = await this.fetchGeoJson(this.itemBoundaryPath(item));

            this.clearBoundaryHighlight();
            this.clearHoverBoundary();
            this.clearHoverLabel();

            this.boundaryLayer = L.geoJSON(geojson, {
                style: {
                    color: '#b95f2d',
                    weight: 2,
                    fillColor: '#e0a06a',
                    fillOpacity: 0.22,
                },
            }).addTo(this.map);

            this.highlightedId = itemId;
        },
        clearBoundaryHighlight(){
            if (this.boundaryLayer && this.map) {
                this.map.removeLayer(this.boundaryLayer);
            }

            this.boundaryLayer = null;
            this.highlightedId = '';
        },
        async previewBoundary(itemId){
            if (!this.mapReady || this.highlightedId === itemId) {
                return;
            }

            const item = this.items.find((row) => row.id === itemId);
            if (!item) {
                return;
            }

            try {
                const geojson = await this.fetchGeoJson(this.itemBoundaryPath(item));
                if (!this.hasDrawableGeometry(geojson)) {
                    return;
                }

                this.clearHoverBoundary();
                this.hoverBoundaryLayer = L.geoJSON(geojson, {
                    style: {
                        color: '#1f6b45',
                        weight: 2,
                        fillColor: '#62a87c',
                        fillOpacity: 0.16,
                        dashArray: '6 6',
                    },
                }).addTo(this.map);
            } catch (error) {
                console.error('Failed to preview boundary geojson.', error);
            }
        },
        clearHoverBoundary(){
            if (this.hoverBoundaryLayer && this.map) {
                this.map.removeLayer(this.hoverBoundaryLayer);
            }

            this.hoverBoundaryLayer = null;
        },
        showHoverLabel(item){
            if (!this.mapReady || !this.map) {
                return;
            }

            this.clearHoverLabel();
            this.hoverLabelMarker = L.marker([Number(item.lat), Number(item.lng)], {
                interactive: false,
                keyboard: false,
                zIndexOffset: 1000,
                icon: L.divIcon({
                    className: 'city-hover-label-wrap',
                    html: `<div class="city-hover-label">${this.escapeHtml(item.display_name || item.city || '')}</div>`,
                    iconSize: null,
                }),
            }).addTo(this.map);
        },
        clearHoverLabel(){
            if (this.hoverLabelMarker && this.map) {
                this.map.removeLayer(this.hoverLabelMarker);
            }

            this.hoverLabelMarker = null;
        },
        escapeHtml(value){
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },
        renderModalMap(item, geojson){
            if (!L || !this.$refs.modalMapBox) {
                return;
            }

            if (!this.modalMap) {
                this.modalMap = L.map(this.$refs.modalMapBox, {
                    zoomControl: false,
                    attributionControl: false,
                    dragging: false,
                    scrollWheelZoom: false,
                    doubleClickZoom: false,
                    touchZoom: false,
                    boxZoom: false,
                    keyboard: false,
                });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.modalMap);
            }

            if (this.modalBoundaryLayer) {
                this.modalMap.removeLayer(this.modalBoundaryLayer);
                this.modalBoundaryLayer = null;
            }

            if (geojson) {
                this.modalBoundaryLayer = L.geoJSON(geojson, {
                    style: {
                        color: '#9f5227',
                        weight: 2,
                        fillColor: '#d8a56d',
                        fillOpacity: 0.24,
                    },
                }).addTo(this.modalMap);

                const bounds = this.modalBoundaryLayer.getBounds();
                if (bounds.isValid()) {
                    this.modalMap.fitBounds(bounds.pad(0.18));
                } else {
                    this.modalMap.setView([Number(item.lat), Number(item.lng)], 7);
                }
            } else {
                // Store type: show precise GPS pin, no boundary
                const latlng = [Number(item.lat), Number(item.lng)];
                L.marker(latlng, {
                    icon: L.divIcon({
                        className: 'mug-marker',
                        html: '',
                        iconSize: [14, 14],
                        iconAnchor: [7, 7],
                    }),
                }).addTo(this.modalMap);
                this.modalMap.setView(latlng, 16);
            }

            setTimeout(() => {
                if (this.modalMap) {
                    this.modalMap.invalidateSize();
                }
            }, 0);
        },
        destroyModalMap(){
            if (this.modalMap) {
                this.modalMap.remove();
            }

            this.modalMap = null;
            this.modalBoundaryLayer = null;
        },
        async locateUser(){
            if (!navigator.geolocation || !this.mapReady) {
                this.locationStatus = '瀏覽器不支援定位';
                return;
            }

            this.locationStatus = '定位中...';

            navigator.geolocation.getCurrentPosition((position) => {
                const { latitude, longitude, accuracy } = position.coords;

                if (this.currentLocationMarker && this.map) {
                    this.map.removeLayer(this.currentLocationMarker);
                }

                if (this.currentLocationCircle && this.map) {
                    this.map.removeLayer(this.currentLocationCircle);
                }

                this.currentLocationMarker = L.circleMarker([latitude, longitude], {
                    radius: 8,
                    color: '#fff',
                    weight: 3,
                    fillColor: '#2a7fff',
                    fillOpacity: 1,
                }).addTo(this.map);

                this.currentLocationCircle = L.circle([latitude, longitude], {
                    radius: accuracy,
                    color: '#2a7fff',
                    weight: 1,
                    fillColor: '#2a7fff',
                    fillOpacity: 0.12,
                }).addTo(this.map);

                this.map.setView([latitude, longitude], 8);
                this.locationStatus = '已定位目前位置';
            }, () => {
                this.locationStatus = '定位失敗';
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
            });
        },
        itemImageUrl(item){
            return `./output/mugs/${item.id}/mug.jpg`;
        },
        withTs(url){
            const sep = url.includes('?') ? '&' : '?';
            return `${url}${sep}t=${Date.now()}`;
        },
        itemMetaUrl(item){
            return `./output/mugs/${item.id}/meta.json`;
        },
        itemCityMetaPath(item){
            return `output/cities/${item.city_key}/meta.json`;
        },
        itemBoundaryPath(item){
            return `output/cities/${item.city_key}/boundary.geojson`;
        },
        itemBoundaryUrl(item){
            return `./${this.itemBoundaryPath(item)}`;
        },
        clearSearch(){
            this.searchKeyword = '';
            this.debouncedKeyword = '';
        },
        onImgError(e){
            const image = e.target;
            image.style.display = 'none';
            const fallback = image.parentElement
                ? image.parentElement.querySelector('.mug-image-fallback')
                : null;
            if (fallback) {
                fallback.hidden = false;
            }
        },
        async openJsonViewer(url, title){
            this._jsonViewerTrigger = document.activeElement;
            this.jsonViewerTitle = title;
            this.jsonViewerContent = '載入中...';
            this.jsonViewerVisible = true;
            await this.$nextTick();
            const overlay = this.$el.querySelector('.json-viewer-overlay');
            if (overlay) disableBodyScroll(overlay);
            if (this.$refs.jsonViewerCloseBtn) {
                this.$refs.jsonViewerCloseBtn.focus();
            }
            try {
                const response = await fetch(this.withTs(url));
                const data = await response.json();
                this.jsonViewerContent = JSON.stringify(data, null, 2);
            } catch (error) {
                this.jsonViewerContent = '載入失敗';
            }
        },
        closeJsonViewer(){
            const overlay = this.$el.querySelector('.json-viewer-overlay');
            if (overlay) enableBodyScroll(overlay);
            const trigger = this._jsonViewerTrigger;
            this.jsonViewerVisible = false;
            this.jsonViewerTitle = '';
            this.jsonViewerContent = '';
            this._jsonViewerTrigger = null;
            if (trigger && typeof trigger.focus === 'function') {
                this.$nextTick(() => trigger.focus());
            }
        },
        hasDrawableGeometry(geojson){
            const features = Array.isArray(geojson.features) ? geojson.features : [];
            let hasGeometry = false;

            features.forEach((feature) => {
                const geometry = feature.geometry || {};
                const type = geometry.type;
                if (type === 'Polygon' || type === 'MultiPolygon') {
                    hasGeometry = true;
                }
            });

            return hasGeometry;
        },
    },
};
</script>
