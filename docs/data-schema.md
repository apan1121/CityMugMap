# 城市杯資料 Schema

## 設計原則

- 一張照片就是一個城市杯收藏項目
- 同城市可以有多筆收藏資料
- `city_key` 用來表示城市層級
- `id` 用來表示單一收藏項目
- 城市共用資料與單一收藏資料分開存放
- `boundary.geojson` 改成每個城市一份，不再重複寫在每個杯子資料夾
- OpenAI API Key 由 `config/config.php` 提供

## 設定檔

路徑：

```text
config/config.php
```

建議內容：

```php
<?php

define('OPENAI_API_KEY', 'sk-xxxx');
define('OPENAI_MODEL', 'gpt-4o-mini');
define('OPENAI_API_BASE', 'https://api.openai.com/v1');
define('CITY_MUG_INPUT_DIR', APP_DIR . '/input');
define('CITY_MUG_OUTPUT_DIR', APP_DIR . '/output');
define('CITY_MUG_USER_AGENT', 'CityMugMap/1.0 (local build script)');
```

規則：

- `config/config.php` 只放本機，不提交版控
- 專案內保留範例檔供複製

## `output/processed_files.json`

用途：

- 記錄哪些來源照片已處理過
- 避免重複呼叫 API

格式：

```json
{
  "2026-03-kyoto-01.jpg": {
    "id": "kyoto-japan-20260319-01",
    "city_key": "kyoto-japan",
    "processed_at": "2026-03-19T00:00:00Z"
  }
}
```

## `output/manual_overrides.json`

用途：

- 當 AI 辨識錯誤時，依檔名手動覆寫

格式：

```json
{
  "2026-03-kyoto-01.jpg": {
    "city": "Kyoto",
    "country": "",
    "display_name": "Starbucks Kyoto Mug",
    "description": "Kyoto is a historic city in Japan known for temples, gardens, and traditional culture.",
    "confidence": 1,
    "taken_at": null
  }
}
```

## 目錄結構

```text
input/
  2026-03-kyoto-01.jpg
  2026-03-kyoto-02.jpg

output/
  main.json
  mugs/
    kyoto-japan-20260319-01/
      mug.jpg
      meta.json
    kyoto-japan-20260319-02/
      mug.jpg
      meta.json
  cities/
    kyoto-japan/
      meta.json
      boundary.geojson
```

## 命名規則

### `city_key`

格式：

```text
<city-slug>-<country-slug>
```

例如：

```text
kyoto-japan
```

### `id`

格式：

```text
<city_key>-<yyyymmdd>-<seq>
```

例如：

```text
kyoto-japan-20260319-01
kyoto-japan-20260319-02
```

說明：

- 同一天同城市可有多筆收藏
- `seq` 建議固定兩位數

## `output/cities/<city_key>/meta.json`

用途：

- 城市層級共用資料
- 前端地圖與 GeoJSON 的共用來源

格式：

```json
{
  "city_key": "kyoto-japan",
  "city": "Kyoto",
  "country": "Japan",
  "lat": 35.0116,
  "lng": 135.7681,
  "updated_at": "2026-03-19T00:00:00Z"
}
```

說明：

- `output/cities/<city_key>/meta.json` 只保留城市本身資料
- `boundary.geojson` 路徑固定由 `city_key` 推導，不再存進 metadata

## `output/main.json`

用途：

- 前端列表頁資料來源
- 地圖 marker 資料來源

每筆欄位：

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
  "source_image": "input/2026-03-kyoto-01.jpg",
  "created_at": "2026-03-19T00:00:00Z"
}
```

路徑推導規則：

- 圖片：`output/mugs/<id>/mug.jpg`
- mug metadata：`output/mugs/<id>/meta.json`
- 城市 metadata：`output/cities/<city_key>/meta.json`
- 城市邊界：`output/cities/<city_key>/boundary.geojson`

## `output/mugs/<id>/meta.json`

用途：

- 單一收藏項目的完整資料
- 未來詳情頁資料來源

每筆欄位：

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
  "source_image": "2026-03-kyoto-01.jpg",
  "confidence": 0.94,
  "taken_at": null,
  "created_at": "2026-03-19T00:00:00Z",
  "updated_at": "2026-03-19T00:00:00Z"
}
```

說明：

- `meta.json` 不再記錄相對路徑
- 前端與腳本只靠 `id` 與 `city_key` 自動推導檔案位置

## 邊界資料

檔名固定：

```text
output/cities/<city_key>/boundary.geojson
```

規則：

- 同一個 `city_key` 只保留一份共用 GeoJSON
- 若成功取得城市邊界，寫入完整 GeoJSON
- 若查不到邊界，可不建立檔案，或建立空 FeatureCollection
- 前端必須允許 `boundary` 缺失

## 去重策略

- `processed_files.json` 用來源檔名追蹤是否已處理
- 不以 `city_key` 去重
- 即使 `city_key` 相同，只要是不同照片，就產生新 `id`
- 城市共用資料以 `city_key` 覆蓋更新

## 刪除策略

- 刪除單一 `id` 時，只刪該收藏項目的資料夾
- 單一收藏項目資料夾固定放在 `output/mugs/<id>/`
- 若刪除後該 `city_key` 已無其他收藏項目，才刪掉 `output/cities/<city_key>/`

## 前端使用原則

- 地圖 marker 以 `main.json` 每筆資料為單位
- 若多筆收藏屬於同一城市，marker 可重疊
- GeoJSON 與城市中心點以 `city_key` 推導共用城市資料路徑
- 後續若要做城市聚合，可直接用 `city_key` 分組
