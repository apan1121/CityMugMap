# City Mug Map

Byron 的個人 Starbucks 城市杯收藏網站。

這個專案分成兩個部分：
- PHP 處理流程：讀取 `input/` 內的城市杯照片，呼叫 OpenAI 分析城市名稱，再補上地理資訊與城市邊界資料，輸出到 `output/`
- 靜態前端網站：讀取 `output/main.json`，用 OpenStreetMap / Leaflet 呈現地圖模式與列表模式

## 功能

- 一張照片代表一筆城市杯收藏
- 同一個城市可以有多筆不同杯子資料
- 透過 OpenAI 視覺分析照片中的城市杯名稱
- 透過 OpenStreetMap / Nominatim 取得座標與 GeoJSON 邊界
- 輸出共享的城市資料與單筆城市杯資料
- 支援手動覆寫辨識結果
- 支援列出、互動刪除、指定 `id` 刪除城市杯資料
- 前端支援地圖模式、列表模式、搜尋、marker 點擊 modal、GeoJSON 邊界預覽

## 目錄結構

```text
CityMugMap/
├── config/
│   ├── config.php
│   └── config_sample.php
├── input/
├── output/
│   ├── cities/
│   │   └── <city_key>/
│   │       ├── meta.json
│   │       └── boundary.geojson
│   ├── mugs/
│   │   └── <id>/
│   │       ├── mug.jpg
│   │       └── meta.json
│   ├── main.json
│   ├── manual_overrides.json
│   └── processed_files.json
├── scripts/
│   ├── process_mugs.php
│   ├── manage_mugs.php
│   └── lib/
├── src/
│   ├── css/
│   ├── img/
│   └── js/
├── docs/
│   ├── data-schema.md
│   └── starbucks-city-mug-plan.md
└── index.html
```

## 環境需求

- PHP 7.4 以上
- Node.js / npm
- OpenAI API Key
- 網路可連到：
  - OpenAI API
  - OpenStreetMap Nominatim

如果要正常輸出前端網站，也需要先安裝 npm 套件。

## 設定

先複製設定檔：

```bash
cp config/config_sample.php config/config.php
```

在 `config/config.php` 內至少設定：

```php
define('OPENAI_API_KEY', 'your-openai-api-key');
```

可選設定：
- `OPENAI_MODEL`
- `OPENAI_API_BASE`
- `CITY_MUG_INPUT_DIR`
- `CITY_MUG_OUTPUT_DIR`
- `CITY_MUG_USER_AGENT`

## 城市杯處理流程

把照片放進 `input/` 後，執行：

```bash
php scripts/process_mugs.php
```

只處理某一張：

```bash
php scripts/process_mugs.php --file=IMG_7095.jpg
```

強制重跑某一張：

```bash
php scripts/process_mugs.php --file=IMG_7095.jpg --force
```

說明：
- 程式會逐步 `echo` 出目前處理進度
- 已處理過的圖片會依 `output/processed_files.json` 略過
- `--force` 會先刪除舊輸出，再重新分析

### 如果 AI 辨識失敗

當城市名稱無法可靠辨識時，程式會：
- 停下來
- 顯示圖片路徑
- 要求你手動輸入城市名稱

之後程式會繼續：
- 用城市名稱查地理資料
- 自動補國家
- 寫入 `output/manual_overrides.json`

下次同一張圖再執行時，會優先吃 override。

## 管理城市杯資料

列出全部城市杯：

```bash
php scripts/manage_mugs.php --list
```

指定 `id` 刪除：

```bash
php scripts/manage_mugs.php --delete=sabah-malaysia-20260319-01
```

互動式刪除：

```bash
php scripts/manage_mugs.php --interactive
```

## 輸出資料格式

### `output/main.json`

整個網站的主索引，前端首頁會直接讀這份資料。

每一筆代表一只城市杯收藏，包含：
- `id`
- `city_key`
- `city`
- `country`
- `display_name`
- `description`
- `lat`
- `lng`
- `source_image`
- `confidence`
- `taken_at`
- `created_at`
- `updated_at`

### `output/mugs/<id>/`

- `mug.jpg`
- `meta.json`

### `output/cities/<city_key>/`

- `meta.json`
- `boundary.geojson`

說明：
- 圖片與杯子 metadata 以 `id` 推導
- 城市 metadata 與 GeoJSON 以 `city_key` 推導
- JSON 內不再落盤相對路徑欄位

更多細節可看 [docs/data-schema.md](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/docs/data-schema.md)。

## 前端開發

安裝套件：

```bash
npm install
```

開發模式：

```bash
npm run dev
```

正式 build：

```bash
npm run build
```

只編正式 bundle：

```bash
npm run build:prod
```

## 前端呈現

前端目前提供：
- 地圖模式
- 列表模式
- 搜尋
- 目前位置定位
- 地圖 marker hover 顯示城市名稱與 GeoJSON 區域
- 點擊 marker 開啟 modal
- modal 內顯示城市杯資訊與城市地圖

主要入口：
- [index.html](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/index.html)
- [src/js/components/MainPage/main.vue](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/src/js/components/MainPage/main.vue)
- [src/css/page/page.scss](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/src/css/page/page.scss)

## SEO

目前已補上的 SEO 項目：
- `title`
- `meta description`
- Open Graph
- Twitter Card
- JSON-LD `CollectionPage`
- favicon / apple-touch-icon

如果未來要上 GitHub Pages，建議再補：
- `canonical`
- 正式 `og:url`
- `robots.txt`
- `sitemap.xml`

## 部署

這個專案的前端可以部署成 GitHub 靜態頁面，但 PHP 腳本不會在 GitHub Pages 上執行。

實際流程通常是：
1. 本機執行 `php scripts/process_mugs.php`
2. 產出或更新 `output/`
3. 本機執行 `npm run build`
4. 將靜態檔部署到 GitHub Pages

也就是：
- PHP 負責資料建置
- GitHub Pages 只負責展示結果

## 注意事項

- `config/config.php` 內有 OpenAI API Key，不要提交到公開 repo
- `output/manual_overrides.json` 建議保留，避免同一張圖每次都重新人工修正
- OpenStreetMap / Nominatim 有使用規範，不適合高頻大量打 API
- `src/img/preview.png` 如果保留，webpack build 可能會因檔案偏大出現 warning；目前社群分享圖實際使用的是 `src/img/preview.jpg`

## 文件

- [docs/starbucks-city-mug-plan.md](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/docs/starbucks-city-mug-plan.md)
- [docs/data-schema.md](/Users/apan1121/workspace/DockerPP_V2/pp_app/web/CityMugMap/docs/data-schema.md)
