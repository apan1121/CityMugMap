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
                <div class="view-switch site-switch">
                    <button
                        type="button"
                        class="switch-button"
                        :class="{ 'is-active': activeView === 'map' }"
                        @click="activeView = 'map'"
                    >
                        地圖模式
                    </button>
                    <button
                        type="button"
                        class="switch-button"
                        :class="{ 'is-active': activeView === 'list' }"
                        @click="activeView = 'list'"
                    >
                        列表模式
                    </button>
                </div>

            </div>
        </header>

        <header class="page-header">
            <div class="page-header-main">
                <p class="page-kicker">Starbucks City Mug Archive</p>
                <div class="page-heading-row">
                    <div>
                        <h1 class="page-title">城市杯收藏地圖</h1>
                        <p class="page-desc">
                            以地圖為主體，整理每一只城市杯的照片、城市資訊與 GeoJSON 邊界。
                        </p>
                    </div>

                    <div class="page-stats">
                        <div class="status-pill">
                            收藏數 <strong>{{ filteredItems.length }}</strong>
                        </div>
                        <div class="status-pill">
                            城市數 <strong>{{ cityCount }}</strong>
                        </div>
                        <div class="status-pill">
                            國家數 <strong>{{ countryCount }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-toolbar">
                <label class="search-box">
                    <span class="search-label">Search</span>
                    <input
                        v-model.trim="searchKeyword"
                        type="text"
                        class="search-input"
                        placeholder="搜尋城市、國家、杯子名稱"
                    >
                </label>

                <div v-if="activeView === 'map'" class="toolbar-actions">
                    <button type="button" class="toolbar-button" @click="fitAllMarkers">
                        縮放至全部收藏
                    </button>
                    <button type="button" class="toolbar-button is-primary" @click="locateUser">
                        定位目前位置
                    </button>
                </div>

            </div>
        </header>

        <section v-show="activeView === 'map'" class="map-stage">
            <h2 class="section-title sr-only">城市杯地圖</h2>
            <div class="map-shell">
                <div ref="mapBox" class="leaflet-map"></div>
            </div>
        </section>

        <section v-if="activeView === 'list'" class="list-section" aria-labelledby="collection-list-title">
            <div class="list-head">
                <div>
                    <p class="section-kicker">Collection</p>
                    <h2 id="collection-list-title" class="section-title">收藏列表</h2>
                </div>
            </div>

            <div class="list-grid">
                <article
                    v-for="item in filteredItems"
                    :key="item.id"
                    class="mug-card"
                    @click="openItemModal(item.id)"
                >
                    <div class="mug-card-image-box">
                        <img
                            :src="itemImageUrl(item)"
                            :alt="`${item.display_name} - ${item.city}, ${item.country}`"
                            class="mug-card-image"
                        >
                    </div>
                    <div class="mug-card-body">
                        <p class="mug-card-location">{{ item.city }} / {{ item.country }}</p>
                        <h3 class="mug-card-title">{{ item.display_name }}</h3>
                        <p class="mug-card-desc">{{ item.description }}</p>
                        <div class="mug-card-footer">
                            <span>{{ item.id }}</span>
                            <span>{{ item.created_at }}</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div
            v-if="modalItem"
            class="mug-modal-overlay"
            @click.self="closeModal"
        >
            <div class="mug-modal">
                <button type="button" class="mug-modal-close" @click="closeModal">
                    ×
                </button>

                <div class="mug-modal-media">
                    <img
                        :src="itemImageUrl(modalItem)"
                        :alt="`${modalItem.display_name} - ${modalItem.city}, ${modalItem.country}`"
                        class="mug-modal-image"
                    >
                </div>

                <div class="mug-modal-content">
                    <p class="mug-modal-id">{{ modalItem.id }}</p>
                    <h2 class="mug-modal-title">{{ modalItem.display_name }}</h2>
                    <p class="mug-modal-location">{{ modalItem.city }}, {{ modalItem.country }}</p>
                    <p class="mug-modal-desc">{{ modalItem.description }}</p>

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
                        <a
                            v-if="modalItem"
                            :href="itemMetaUrl(modalItem)"
                            class="mug-modal-link"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            查看 meta.json
                        </a>
                        <a
                            v-if="modalItem"
                            :href="itemBoundaryUrl(modalItem)"
                            class="mug-modal-link"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            查看 boundary.geojson
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>

<script>
import { linkRegister } from 'lib/common/util';

export default {
    name: 'MainPage',
    data(){
        return {
            activeView: 'map',
            searchKeyword: '',
            siteLogoUrl: './dist/img/coffee_logo.png',
            items: [],
            modalId: '',
            modalGeoStatus: '載入 GeoJSON 中...',
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
        };
    },
    computed: {
        filteredItems(){
            const keyword = this.searchKeyword.toLowerCase();
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
            return new Set(this.items.map((item) => item.city_key)).size;
        },
        countryCount(){
            return new Set(this.items.map((item) => item.country)).size;
        },
    },
    watch: {
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
    },
    beforeUnmount(){
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
        }

        this.destroyModalMap();

        if (this.map) {
            this.map.remove();
        }
    },
    methods: {
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
            try {
                const response = await fetch('./output/main.json');
                const data = await response.json();
                this.items = Array.isArray(data) ? data : [];
            } catch (error) {
                console.error('Failed to load mug items.', error);
                this.items = [];
            }
        },
        async initLeafletMap(){
            const L = await this.waitForLeaflet();
            if (!L || !this.$refs.mapBox) {
                return;
            }

            this.map = L.map(this.$refs.mapBox, {
                zoomControl: false,
                worldCopyJump: true,
                zoomAnimation: false,
                fadeAnimation: false,
                markerZoomAnimation: false,
            }).setView([18, 120], 3);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(this.map);

            L.control.zoom({
                position: 'bottomright',
            }).addTo(this.map);

            this.markerLayerGroup = L.featureGroup().addTo(this.map);
            this.map.on('zoomstart movestart', () => {
                this.clearHoverLabel();
                this.clearHoverBoundary();
            });
            this.mapReady = true;
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
            }, 300);
        },
        waitForLeaflet(){
            return new Promise((resolve) => {
                const maxTries = 80;
                let tries = 0;
                const timer = setInterval(() => {
                    tries += 1;
                    if (window.L) {
                        clearInterval(timer);
                        resolve(window.L);
                        return;
                    }

                    if (tries >= maxTries) {
                        clearInterval(timer);
                        resolve(null);
                    }
                }, 100);
            });
        },
        renderMapItems(){
            if (!this.mapReady || !this.markerLayerGroup) {
                return;
            }

            const L = window.L;
            this.markerLayerGroup.clearLayers();

            this.filteredItems.forEach((item) => {
                const marker = L.circleMarker([Number(item.lat), Number(item.lng)], {
                    radius: 9,
                    color: '#fff8ee',
                    weight: 3,
                    fillColor: '#173221',
                    fillOpacity: 1,
                });

                marker.on('mouseover', () => {
                    this.showHoverLabel(item);
                    this.previewBoundary(item.id);
                });

                marker.on('mouseout', () => {
                    this.clearHoverLabel();
                    this.clearHoverBoundary();
                });

                marker.on('click', () => {
                    this.clearHoverLabel();
                    this.openItemModal(item.id);
                });

                this.markerLayerGroup.addLayer(marker);
            });

            if (this.activeView === 'map') {
                this.fitAllMarkers();
            }
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
        async openItemModal(itemId){
            this.modalId = itemId;
            await this.loadModalGeoJson(itemId);
            await this.highlightBoundary(itemId);
        },
        closeModal(){
            this.modalId = '';
            this.modalGeoStatus = '載入 GeoJSON 中...';
            this.clearBoundaryHighlight();
            this.destroyModalMap();
        },
        async loadModalGeoJson(itemId){
            const item = this.items.find((row) => row.id === itemId);
            if (!item) {
                this.modalGeoStatus = '沒有可用的 GeoJSON';
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

            const response = await fetch(`./${boundaryPath}`);
            const geojson = await response.json();
            this.geoJsonCache[boundaryPath] = geojson;

            return geojson;
        },
        async highlightBoundary(itemId){
            const item = this.items.find((row) => row.id === itemId);
            if (!item || !this.mapReady) {
                return;
            }

            const L = window.L;
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

            const bounds = this.boundaryLayer.getBounds();
            if (bounds.isValid()) {
                this.map.fitBounds(bounds.pad(0.18), {
                    animate: false,
                });
            } else {
                this.map.setView([Number(item.lat), Number(item.lng)], 7, {
                    animate: false,
                });
            }

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
                const L = window.L;
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

            const L = window.L;
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
            const L = window.L;
            if (!L || !this.$refs.modalMapBox) {
                return;
            }

            if (!this.modalMap) {
                this.modalMap = L.map(this.$refs.modalMapBox, {
                    zoomControl: false,
                    attributionControl: false,
                    dragging: true,
                    scrollWheelZoom: false,
                });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.modalMap);
            }

            if (this.modalBoundaryLayer) {
                this.modalMap.removeLayer(this.modalBoundaryLayer);
            }

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
                const L = window.L;

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
        itemMetaUrl(item){
            return `./output/mugs/${item.id}/meta.json`;
        },
        itemBoundaryPath(item){
            return `output/cities/${item.city_key}/boundary.geojson`;
        },
        itemBoundaryUrl(item){
            return `./${this.itemBoundaryPath(item)}`;
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
