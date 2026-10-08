# 📚 my_books — 離線購書追蹤 CLI

一個用 PHP 寫的單檔命令列工具，用來記錄自己買了哪些漫畫／輕小說／書籍系列、各系列買到第幾集，避免重複購買、方便追進度。

資料存在 SQLite 資料庫，**完全離線、不需網路、不需任何外部套件**。也可將 DB 放到 Google Drive 等同步資料夾，讓多台電腦共用。

---

## ✨ 功能特色

- 📥 單筆新增或批次匯入購買紀錄
- 🔄 同系列同集數自動更新（不會產生重複資料）
- 🔍 查詢某系列目前買到最新第幾集
- 📋 兩種清單模式：簡易清單與表格清單
- 🗑️ 依編號刪除紀錄（刪除前會二次確認）
- 🤏 模糊比對書名，找出可能打錯字或重複的系列
- 🗓️ 預設記錄購買日期，可自訂

---

## 🔧 環境需求

- PHP **8.0 以上**（使用了 `str_contains`、具名引數等語法）
- 已啟用 PHP 內建擴充：
  - `pdo_sqlite`（資料庫存取）
  - `mbstring`（中文書名對齊／截斷）

確認環境：

```bash
php -v
php -m | grep -E 'pdo_sqlite|mbstring'
```

---

## 🚀 安裝與啟動

```bash
git clone <repo-url> my_books
cd my_books

# 直接執行（首次執行會自動建立 sqlite/ 與 batch_file/ 目錄及資料表）
php book-cli.php help
```

或賦予執行權限後直接呼叫：

```bash
chmod +x book-cli.php
./book-cli.php help
```

> 資料庫檔案：預設 `./sqlite/books.db`，可用環境變數 `BOOKS_DB` 指定（首次執行自動建立）
> 批次匯入檔目錄：`./batch_file/`

---

## ☁️ 多台電腦共用 DB（Google Drive）

程式會優先讀取環境變數 `BOOKS_DB` 作為資料庫路徑，未設定時才使用 `./sqlite/books.db`。
把 DB 放進 Google Drive 電腦版的同步資料夾，各台電腦都指向該檔案即可共用。

### WSL（Windows 上的 Linux）

WSL 預設不會掛載 Google Drive 的虛擬磁碟，需手動掛載：

```bash
# 1. 掛載 G:
sudo mkdir -p /mnt/g
sudo mount -t drvfs G: /mnt/g

# 2. 首次搬移：把現有 DB 複製到雲端硬碟
mkdir -p "/mnt/g/我的雲端硬碟/my_books"
cp sqlite/books.db "/mnt/g/我的雲端硬碟/my_books/books.db"

# 3. 設定環境變數
echo 'export BOOKS_DB="/mnt/g/我的雲端硬碟/my_books/books.db"' >> ~/.bashrc
source ~/.bashrc
```

開機自動掛載可在 `/etc/fstab` 加入：

```
G: /mnt/g drvfs defaults 0 0
```

### 其他環境

```bash
# Windows（直接執行 PHP）
setx BOOKS_DB "G:\我的雲端硬碟\my_books\books.db"

# macOS
export BOOKS_DB="$HOME/Library/CloudStorage/GoogleDrive-<帳號>/My Drive/my_books/books.db"
```

### 使用須知

- **避免兩台電腦同時寫入**：Google Drive 只做檔案同步，不會幫 SQLite 鎖定。同步完成前兩邊都修改，會產生衝突副本（如 `books (1).db`），其中一邊的修改會遺失。換電腦前請先確認 Drive 顯示「已同步」。
- 建議將該資料夾設為「**可離線存取**」或使用「**鏡像**」模式，避免串流模式下讀寫延遲。
- 不要開啟 SQLite WAL 模式（會產生 `-wal`、`-shm` 檔，同步時容易損毀）。

---

## 📖 指令說明

| 指令 | 語法 | 說明 |
|------|------|------|
| `add` | `add "系列" 集數 [store] [date] [notes]` | 新增單筆紀錄 |
| `batch` | `batch <txt檔 \| -> <store> [date]` | 從檔案或 STDIN 批次匯入 |
| `latest` | `latest "系列關鍵字"` | 查詢某系列目前買到最新第幾集 |
| `list` | `list [系列關鍵字] [--no-date]` | 簡易清單（每系列僅顯示最新集數） |
| `list-all` | `list-all [系列關鍵字]` | 表格清單（含編號、通路、日期、備註） |
| `delete` | `delete <id>` | 依編號刪除（執行前需確認 `y/N`） |
| `fuzzy-series` | `fuzzy-series [threshold]` | 模糊比對相似書名，閾值 0~1，預設 `0.8` |
| `help` | `help` / `-h` / `--help` | 顯示使用說明 |

### 參數預設值

- `store`（通路）：未提供時為 **`博客來`**
- `date`（日期）：未提供時為 **執行當天**，格式必須是 `YYYY-MM-DD`
- `notes`（備註）：未提供時為空字串

---

## 🧪 使用範例

### 新增單筆

```bash
# 最簡：系列 + 集數（通路預設博客來、日期預設今天）
php book-cli.php add "航海王" 113

# 完整：指定通路、日期、備註
php book-cli.php add "藥師少女的獨語" 16 金石堂 2026-06-07 "限定版"
```

> 若該系列該集數已存在，會自動改為**更新**日期、通路與備註。

### 批次匯入

批次檔每行格式為 `<系列名稱> <集數>`（以空白分隔，集數為行尾數字）：

```text
GACHIAKUTA 17
航海王 113
藥師少女的獨語 16
青春之箱 23
```

匯入指令：

```bash
# 從 batch_file/ 目錄下的檔案匯入（檔名可省略路徑）
php book-cli.php batch 2026-06-07.txt 博客來 2026-06-07

# 也可使用完整路徑
php book-cli.php batch batch_file/2026-06-07.txt 博客來

# 從 STDIN 匯入（'-' 代表標準輸入）
cat 2026-06-07.txt | php book-cli.php batch - 博客來
```

匯入完成後會回報：新增筆數、更新筆數、跳過格式的行數與總行數。

### 查詢最新集數

```bash
php book-cli.php latest 航海王
# 🎉 已買到 航海王 第 113 集（博客來 購入）。
```

### 列出清單

```bash
# 簡易清單（每系列顯示最新集數 + 購買日期）
php book-cli.php list

# 隱藏日期
php book-cli.php list --no-date

# 依關鍵字過濾
php book-cli.php list 航海

# 表格清單（含編號、通路、日期、備註）
php book-cli.php list-all
```

### 刪除紀錄

```bash
php book-cli.php list-all          # 先查出要刪除的編號
php book-cli.php delete 42          # 會顯示摘要並要求確認 (y/N)
```

### 模糊比對書名

找出可能因打錯字而被當成不同系列的紀錄：

```bash
php book-cli.php fuzzy-series        # 預設相似度 0.8
php book-cli.php fuzzy-series 0.85   # 提高門檻
```

---

## 🗂️ 資料結構

資料表 `purchases`：

| 欄位 | 型別 | 說明 |
|------|------|------|
| `id` | INTEGER | 主鍵，自動遞增 |
| `series` | TEXT | 系列名稱（必填） |
| `volume` | INTEGER | 集數（必填） |
| `store` | TEXT | 購買通路 |
| `notes` | TEXT | 備註 |
| `bought_at` | TEXT | 購買日期（`YYYY-MM-DD`） |

> 以 `(series, volume)` 為唯一鍵，因此同系列同集數只會有一筆，再次匯入即更新。

---

## 📁 專案結構

```
my_books/
├── book-cli.php        # 主程式（單檔 CLI）
├── readme.md           # 本說明文件
├── .gitignore          # 忽略清單
├── sqlite/
│   └── books.db        # 範例 SQLite 資料庫（未設定 BOOKS_DB 時使用）
└── batch_file/
    ├── sample.txt      # 批次匯入範例
    └── YYYY-MM-DD.txt  # 依日期命名的批次檔
```

---

## ⚠️ 注意事項

- 日期一律使用 `YYYY-MM-DD` 格式，否則會報錯。
- 批次檔每行**結尾必須是數字（集數）**，不符格式的行會被跳過並計數。
- repo 內的 `sqlite/books.db` 僅作為範例；實際資料請以 `BOOKS_DB` 指向 Google Drive 等位置，避免個人資料被 commit，並自行定期備份。
