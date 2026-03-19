# 星巴克城市杯靜態網站規劃

## 目標

建立一個可放在 GitHub 的靜態網站，用來展示已收藏的星巴克城市杯。

整體流程分成兩部分：

1. 後台處理腳本：把新放進 `input/` 的照片做 AI 辨識、城市資料整理、GeoJSON 下載與輸出。
2. 前台靜態網站：讀取 `output/` 與 `main.json`，在 OpenStreetMap 地圖與列表模式中展示所有城市杯。

---

## 核心需求拆解

### 1. 照片輸入與自動處理

- 未來每次只要把新買的城市杯照片放進 `input/`。
- 執行 PHP 腳本後，自動：
  - 呼叫 GPT API 分析照片內容
  - 取得城市名稱
  - 產生城市杯名稱與簡介
  - 依城市名稱查詢地理座標與 GeoJSON 邊界
  - 將整理後資料輸出到 `output/<city-slug>/`

### 2. 輸出資料格式

每張照片代表一個城市杯收藏項目，輸出時以「單一城市杯項目」為單位建立資料夾，內容至少包含：

- 城市杯照片
- `meta.json`
- `boundary.geojson`

另外在 `output/` 底下維護：

- `main.json`

`main.json` 作為網站列表與地圖 marker 的主資料來源。

### 3. 前端展示網站

- 使用 OpenStreetMap 顯示世界地圖
- 在地圖上標記所有城市杯位置
- 額外顯示使用者目前位置
- 支援：
  - 大地圖模式
  - 條列模式
  - 搜尋城市 / 國家 / 關鍵字

---

## 建議目錄結構

```text
input/
  2026-03-kyoto-01.jpg
  2026-03-osaka-01.jpg

output/
  main.json
  kyoto-japan-20260319-01/
    mug.jpg
    meta.json
    boundary.geojson
  kyoto-japan-20260319-02/
    mug.jpg
    meta.json
    boundary.geojson
  osaka-japan-20260319-01/
    mug.jpg
    meta.json
    boundary.geojson

scripts/
  process_mugs.php
  lib/
    OpenAIClient.php
    GeoService.php
    FileService.php
    JsonService.php

src/
  js/
  css/
```

---

## 建議資料格式

### `output/<city-slug>/meta.json`

```json
{
  "id": "kyoto-japan-20260319-01",
  "city_key": "kyoto-japan",
  "city": "Kyoto",
  "country": "Japan",
  "display_name": "Starbucks Kyoto Mug",
  "description": "Kyoto is a historic city in Japan known for temples, gardens, and traditional culture.",
  "lat": 35.0116,
  "lng": 135.7681,
  "image": "./mug.jpg",
  "boundary_file": "./boundary.geojson",
  "source_image": "2026-03-kyoto-01.jpg",
  "taken_at": null,
  "created_at": "2026-03-19T00:00:00Z"
}
```

### `output/main.json`

```json
[
  {
    "id": "kyoto-japan-20260319-01",
    "city_key": "kyoto-japan",
    "city": "Kyoto",
    "country": "Japan",
    "display_name": "Starbucks Kyoto Mug",
    "description": "Kyoto is a historic city in Japan known for temples, gardens, and traditional culture.",
    "lat": 35.0116,
    "lng": 135.7681,
    "image": "output/kyoto-japan-20260319-01/mug.jpg",
    "meta": "output/kyoto-japan-20260319-01/meta.json",
    "boundary": "output/kyoto-japan-20260319-01/boundary.geojson"
  }
]
```

---

## 後台腳本規劃

### A. GPT 圖像辨識

PHP 腳本讀取 `input/` 中的新照片後，呼叫 OpenAI Vision 模型，要求回傳結構化 JSON：

- `city`
- `country`
- `display_name`
- `description`
- `confidence`

建議要求模型：

- 只輸出 JSON
- 若無法判斷城市，回傳 `unknown`
- 若杯上出現多個地名，選擇最主要城市名

### B. 城市地理資訊取得

需要兩層資料：

1. 地圖 marker 用的經緯度
2. 城市邊界 GeoJSON

建議來源：

- Nominatim / OpenStreetMap：查詢城市座標
- Overpass API 或 OSM 邊界資料服務：取得行政區邊界

風險：

- 城市名稱可能有重名
- 並非所有城市都能穩定拿到完整 polygon
- 有些杯子可能對應的是區域、州、省或觀光地，而不是標準 city

因此需要設計 fallback：

- 先用 `city + country`
- 找不到時退回點位資料
- GeoJSON 失敗時仍保留 `lat/lng`

### C. 輸出與去重

腳本需要處理：

- 將城市名稱轉為穩定 `city_key`
- 依單張照片建立唯一 `id`
- 建立 `output/<id>/`
- 複製或轉存照片為固定檔名
- 產生 `meta.json`
- 產生 `boundary.geojson`
- 更新 `output/main.json`

建議資料策略：

- `city_key` 代表城市層級，例如 `kyoto-japan`
- `id` 代表單一收藏項目，例如 `kyoto-japan-20260319-01`
- 同城市可有多個不同 `id`
- `main.json` 每一筆都代表一張照片、一個城市杯收藏項目

---

## 前端網站規劃

### 1. 地圖模式

建議使用：

- Leaflet + OpenStreetMap

功能：

- 顯示所有城市杯 marker
- 點擊 marker 顯示卡片
- 若有 `boundary.geojson`，可高亮城市範圍
- 顯示目前位置
- 支援縮放到所有收藏的範圍

### 2. 列表模式

列表卡片顯示：

- 城市杯照片
- 城市名稱
- 國家
- 一段介紹

功能：

- 關鍵字搜尋
- 依國家 / 地區篩選
- 點擊後同步定位到地圖

### 3. GitHub 靜態部署注意事項

因為前端會部署到 GitHub Pages，網站本身應：

- 只讀取靜態 JSON / GeoJSON
- 不依賴即時後端 API
- 所有前處理都在本地 PHP 腳本完成後再 commit 到 repo

也就是說：

- PHP 腳本不是部署到 GitHub Pages 上執行
- PHP 腳本是你的本機資料建置工具

---

## 技術方案建議

### 建議拆成兩條線

#### 線 1: 資料建置

- PHP 8+
- OpenAI API
- OSM Nominatim / Overpass
- 本機批次執行

#### 線 2: 靜態網站

- 既有 Vue 3 專案可直接沿用
- Leaflet 顯示地圖
- 讀取 `output/main.json`

---

## 實作階段建議

### Phase 1: 定義資料格式

- 建立 `input/` 與 `output/`
- 定義 `meta.json` 與 `main.json`
- 決定 `city_key`、`id` 與圖片命名規則

### Phase 2: 完成 PHP 批次腳本

- 掃描 `input/`
- 呼叫 GPT API 辨識
- 查詢城市座標
- 取得 GeoJSON
- 輸出檔案與 `main.json`

### Phase 3: 完成前端展示

- 改寫首頁為城市杯展示頁
- 加入地圖模式 / 列表模式切換
- 搜尋與定位互動

### Phase 4: 補強品質

- 失敗重試
- API rate limit 處理
- 快取已查詢城市資料
- 手動覆寫機制

---

## 建議補充需求

這些需求很值得一開始就納入：

- `config/config.php` 管理 OpenAI API Key
- `manual_overrides.json`
  - 當 GPT 判錯城市時可手動修正
- `processed_files.json`
  - 避免重複處理同一張圖
- 圖片壓縮
  - 避免 GitHub Pages 載入過慢

---

## 主要風險與注意事項

### 1. 城市辨識不一定 100% 準

杯子圖樣可能不清楚，或圖片角度不足。

建議：

- 保留人工覆核機制
- 將 GPT 回傳信心值存進 metadata

### 2. GeoJSON 資料品質不一

不同城市在 OSM 的邊界資料完整度不同。

建議：

- 邊界不是阻塞條件
- 拿不到 polygon 時仍可只顯示點位

### 3. GitHub Pages 不執行 PHP

這個專案的 PHP 只負責本機建置，最終部署內容仍是靜態檔案。

### 4. API 金鑰不可進版控

`config/config.php` 只能存在本機，不應提交到 Git。

---

## 正式實作計畫

### Milestone 1: 資料骨架定稿

目標：

- 先固定資料格式，避免後續 PHP 與前端各做各的

交付物：

- `input/`、`output/`、`scripts/` 目錄
- `output/main.json` 初始檔
- `output/<id>/meta.json` schema
- `city_key`、`id` 與圖片命名規則文件化

驗收標準：

- 前端與 PHP 都能只依賴這份 schema
- 同一張照片都能推導出唯一 `id`
- 同城市可對應多筆獨立收藏資料

### Milestone 2: 建立 PHP 批次處理器

目標：

- 從 `input/` 自動產出可供網站展示的靜態資料

交付物：

- `scripts/process_mugs.php`
- `scripts/lib/OpenAIClient.php`
- `scripts/lib/GeoService.php`
- `scripts/lib/FileService.php`
- `scripts/lib/JsonService.php`

處理步驟：

1. 掃描 `input/` 內圖片
2. 排除已處理檔案
3. 呼叫 GPT API 分析照片
4. 解析 `city`、`country`、`display_name`、`description`
5. 查詢 `lat/lng`
6. 嘗試取得 `boundary.geojson`
7. 產生單一收藏項目資料夾與靜態 JSON
8. 更新 `output/main.json`

驗收標準：

- 任意放入一張城市杯照片後，執行一次腳本可成功產出一筆收藏資料
- GeoJSON 失敗時，整體流程仍可完成

### Milestone 3: 前端首頁改版

目標：

- 將現有樣板首頁改成城市杯展示頁

交付物：

- 首頁主視覺與基本版面
- 地圖模式
- 列表模式
- 搜尋欄
- 資料讀取邏輯

畫面需求：

- 預設先載入 `output/main.json`
- 地圖上顯示所有 marker
- 列表中顯示卡片
- 地圖與列表可互相定位

驗收標準：

- 至少能展示 3 筆以上假資料
- 桌機與手機版面可正常使用

### Milestone 4: 補強維運能力

目標：

- 讓這個流程可以長期維護，而不是只能跑一次

交付物：

- `processed_files.json`
- `manual_overrides.json`
- 錯誤重試與錯誤紀錄
- 地理查詢快取

驗收標準：

- 重複執行腳本不會重建同一筆資料
- 人工可覆寫 GPT 誤判結果

---

## 工作拆分

### A. 資料層

- 定義主索引與單收藏項目 metadata schema
- 決定欄位是否保留 `confidence`、`source_image`、`taken_at`、`updated_at`
- 定義相對路徑，避免 GitHub Pages 路徑錯誤

### B. AI 辨識層

- 設計 prompt
- 決定 Vision model
- 定義嚴格 JSON 回傳格式
- 增加低信心時的 fallback 與人工覆寫入口

### C. 地理資料層

- 定義城市名稱查詢順序
- 地名衝突時以 `country` 輔助
- 無 polygon 時退回 marker-only 模式

### D. 靜態輸出層

- 複製或壓縮原圖
- 建立收藏項目資料夾
- 寫入 `meta.json`
- 寫入 `boundary.geojson`
- 重新排序與寫回 `main.json`

### E. 前端展示層

- 載入清單資料
- 搜尋與模式切換
- Marker / 卡片互動
- 定位目前位置
- 高亮 GeoJSON 邊界

---

## 技術決策

### 1. 為什麼用 PHP 做建置腳本

- 你已經明確指定 PHP
- GitHub Pages 不執行 PHP，但本機建置工具完全適合用 PHP
- 專案本身已有 `composer.json` 與 `crawler/` 結構，可自然延伸

### 2. 為什麼前端資料要完全靜態化

- GitHub Pages 只能穩定提供靜態檔
- 頁面部署後不應依賴 OpenAI 或 OSM API
- 成本、速度與穩定性都會更好

### 3. 為什麼要保留人工覆寫

- 城市杯不一定只寫標準城市名稱
- 杯身設計可能有藝術字、縮寫、地區名或多城市元素
- 只靠模型判斷，長期一定會遇到誤判案例

---

## 開發順序建議

### 第一輪

- 建立資料夾結構
- 寫假資料 `main.json`
- 前端先用假資料做地圖與列表頁

目的：

- 先把展示面完成，確保資料 schema 夠用

### 第二輪

- 寫 `process_mugs.php`
- 接 OpenAI API
- 接地理資料服務

目的：

- 打通 input 到 output 的真實流程

### 第三輪

- 補 `processed_files.json`
- 補 `manual_overrides.json`
- 補圖片壓縮與錯誤處理

目的：

- 讓這個流程可長期使用

---

## MVP 驗收清單

- 建立 `input/` 與 `output/`
- 至少一張圖片可成功辨識出城市
- 可產出 `output/<id>/meta.json`
- 可產出 `output/main.json`
- 前端可讀到 `main.json`
- 地圖上可顯示 marker
- 列表模式可顯示圖片與文字
- 可使用瀏覽器定位取得目前位置

---

## 後續擴充項目

- 城市杯詳情頁
- 同城市多收藏項目支援
- 年份、系列、購買日期欄位
- 收藏數量統計
- 國家 / 洲別篩選
- 自動產生縮圖
- 依 GeoJSON 自動縮放到該城市範圍

---

## 第一版 MVP 建議

先做最小可用版本：

1. 照片放入 `input/`
2. PHP 腳本辨識城市名
3. 查出 `lat/lng`
4. 若成功則下載 GeoJSON
5. 產生 `output/<slug>/meta.json`
6. 更新 `output/main.json`
7. Vue 首頁讀取 `main.json`
8. 顯示地圖與列表切換

這樣先把主流程打通，再補收藏頁設計與資料修正機制。

---

## 下一步

下一步我建議直接做兩件事：

1. 建立資料夾與資料格式骨架
2. 寫第一版 `process_mugs.php` 與前端首頁骨架

如果要繼續，我可以下一步直接開始實作第一版專案骨架。
