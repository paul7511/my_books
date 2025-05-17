#!/usr/bin/env php
<?php
/**
 * book_cli.php — 離線購書追蹤 CLI
 * SQLite：./sqlite/books.db
 *
 * 欄位：series, volume, store, notes, bought_at
 *   - bought_at：購買日期 (YYYY-MM-DD)。未傳入時預設為新增當天日期
 *
 * 指令摘要：
 *   php book_cli.php add      "系列" 集數 [store] [date] [notes]
 *   php book_cli.php latest   "系列關鍵字"
 *   php book_cli.php list     [系列關鍵字] [--no-date]       # 新增參數 --no-date 用於隱藏日期
 *   php book_cli.php list-all [系列關鍵字]
 *   php book_cli.php batch    <txt 檔名 | -> <store> [date]  # 每行：<系列> <集數>
 */

//---------------------------------------------
// 路徑設定
//---------------------------------------------
$baseDir  = dirname(__FILE__);
$dbDir    = $baseDir . '/sqlite';
$batchDir = $baseDir . '/batch_file';
if (!is_dir($dbDir))    mkdir($dbDir, 0755, true);
if (!is_dir($batchDir)) mkdir($batchDir, 0755, true);
$dbFile   = $dbDir . '/books.db';

//---------------------------------------------
// 初始化資料庫
//---------------------------------------------
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS purchases (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    series    TEXT    NOT NULL,
    volume    INTEGER NOT NULL,
    store     TEXT,
    notes     TEXT,
    bought_at TEXT,
    UNIQUE(series, volume)
);');

// 若舊資料庫無 bought_at 則增欄
$cols = $pdo->query("PRAGMA table_info(purchases)")->fetchAll(PDO::FETCH_COLUMN,1);
if (!in_array('bought_at', $cols, true)) {
    $pdo->exec('ALTER TABLE purchases ADD COLUMN bought_at TEXT');
}

//---------------------------------------------
// 公用函式
//---------------------------------------------
function err(string $msg, bool $exit = true): void {
    fwrite(STDERR, "$msg\n");
    if ($exit) exit(1);
}

function usage(): void {
    echo "\nUsage:\n".
         "  php book_cli.php add      \"系列\" 集數 [store] [date] [notes]\n".
         "  php book_cli.php latest   \"系列關鍵字\"\n".
         "  php book_cli.php list     [系列關鍵字] [--no-date]\n".
         "  php book_cli.php list-all [系列關鍵字]\n".
         "  php book_cli.php batch    <txt 檔名 | -> <store> [date]\n\n".
         "  batch : 每行 <系列名稱> <集數>；檔名預設於 ./batch_file/；'-' 代表 STDIN\n\n";
    exit;
}

//---------------------------------------------
// 解析指令
//---------------------------------------------
$cmd = $argv[1] ?? '';
if (!$cmd || in_array($cmd, ['-h','--help','help'])) usage();

switch ($cmd) {
    // 新增單筆
    case 'add':
        if ($argc < 4) err('add 需要：系列 集數 [store] [date] [notes]');
        [$series, $vol] = [$argv[2], (int)$argv[3]];
        $store = $argv[4] ?? '';
        $date  = $argv[5] ?? date('Y-m-d');
        $notes = $argv[6] ?? '';
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('日期格式須為 YYYY-MM-DD');
        addOrUpdate($pdo, $series, $vol, $store, $notes, $date, true);
        break;

    // 批次匯入（TEXT）
    case 'batch':
        if ($argc < 4) err('batch 需要 <txt 檔名 | -> <store> [date]');
        $source = $argv[2];
        $store  = $argv[3];
        $date   = $argv[4] ?? date('Y-m-d');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) err('日期格式須為 YYYY-MM-DD');
        handleBatchText($pdo, $source, $store, $date);
        break;

    case 'latest':
        if ($argc < 3) err('latest 需要系列關鍵字');
        showLatest($pdo, $argv[2]);
        break;

    case 'list':
        // 解析 [系列關鍵字] 及可選 --no-date 旗標
        $kw = '';
        $showDate = true;
        for ($i = 2; $i < $argc; $i++) {
            $arg = $argv[$i];
            if ($arg === '--no-date' || $arg === '--hide-date') {
                $showDate = false;
            } else {
                // 第一個非旗標視為關鍵字，其餘忽略
                if ($kw === '') $kw = $arg;
            }
        }
        listSimple($pdo, $kw, $showDate);
        break;

    case 'list-all':
        $kw = $argv[2] ?? '';
        listTable($pdo, $kw);
        break;

    default:
        err("未知指令：$cmd\n", false);
        usage();
}

//---------------------------------------------
// 功能函式們
//---------------------------------------------
function addOrUpdate(PDO $pdo,string $series,int $vol,string $store,string $notes,string $date,bool $echo=false): bool|string {
    // 嘗試 insert
    try{
        $stmt=$pdo->prepare('INSERT INTO purchases (series,volume,store,notes,bought_at) VALUES (:s,:v,:st,:n,:d)');
        $stmt->execute([':s'=>$series,':v'=>$vol,':st'=>$store,':n'=>$notes,':d'=>$date]);
        if($echo) echo "✅ 已加入 {$series} 第 {$vol} 集 ({$date})\n";
        return 'insert';
    }catch(PDOException $e){
        if(!str_contains($e->getMessage(),'UNIQUE')) throw $e;
        // 重複 → 更新 date 與 notes（store 也更新）
        $upd=$pdo->prepare('UPDATE purchases SET bought_at=:d, notes=:n, store=:st WHERE series=:s AND volume=:v');
        $upd->execute([':d'=>$date,':n'=>$notes,':st'=>$store,':s'=>$series,':v'=>$vol]);
        if($echo) echo "🔄 已更新 {$series} 第 {$vol} 集 的日期/備註 ({$date})\n";
        return 'update';
    }
}

function getStream(string $source, string $batchDir){
    if($source==='-'){
        $stream=fopen('php://stdin','r');
        if(!$stream) err('無法讀取 STDIN');
        return $stream;
    }
    if(!file_exists($source)){
        $alt=$batchDir.'/'.ltrim($source,'/');
        if(file_exists($alt)) $source=$alt;
    }
    if(!file_exists($source)) err("檔案不存在：$source");
    $stream=fopen($source,'r'); if(!$stream) err('無法開啟檔案');
    return $stream;
}

function handleBatchText(PDO $pdo,string $source,string $store,string $date){
    global $batchDir;
    $stream=getStream($source,$batchDir);
    $pdo->beginTransaction();
    [$insert,$update,$total,$skip]=[0,0,0,0];
    while(($line=fgets($stream))!==false){
        $total++; $line=trim($line); if($line==='')continue;
        if(!preg_match('/^(.*)\s+(\d+)$/u',$line,$m)){ $skip++; continue; }
        $series=trim($m[1]);$vol=(int)$m[2];
        $res=addOrUpdate($pdo,$series,$vol,$store,'',$date,false);
        if($res==='insert')$insert++;elseif($res==='update')$update++;
    }
    $pdo->commit();fclose($stream);
    echo "\n✅ 批次完成：新增 {$insert} 筆；更新 {$update} 筆；跳過格式 {$skip} 行；總計 {$total} 行\n";
}

function showLatest(PDO $pdo,string $kw){
    $stmt=$pdo->prepare('SELECT series,MAX(volume) as volume,store FROM purchases WHERE series LIKE :kw GROUP BY series ORDER BY volume DESC LIMIT 1');
    $stmt->execute([':kw'=>"%$kw%"]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if($row){$store=$row['store']?:'—';echo "🎉 已買到 {$row['series']} 第 {$row['volume']} 集（{$store} 購入）。\n";}else{echo "❌ 尚未購買 {$kw} 任何集數。\n";}
}

function listTable(PDO $pdo,string $kw){
    $sql='SELECT p1.series,p1.volume,p1.store,p1.bought_at,p1.notes FROM purchases p1
          INNER JOIN (SELECT series,MAX(volume) mv FROM purchases GROUP BY series) p2
          ON p1.series=p2.series AND p1.volume=p2.mv';
    $params=[];
    if($kw){$sql.=' WHERE p1.series LIKE :kw';$params[':kw']="%$kw%";}
    $sql.=' ORDER BY p1.series';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows){echo "(無資料)\n";return;}
    printf("%-20s %-6s %-10s %-12s %-20s\n",'系列','集數','通路','日期','備註');
    echo str_repeat('-',75)."\n";
    foreach($rows as $r){
        printf("%-20s %6d %-10s %-12s %-20s\n",
            mb_strimwidth($r['series'],0,20,'…','UTF-8'),
            $r['volume'],
            $r['store']?:'—',
            $r['bought_at']?:'—',
            mb_strimwidth($r['notes']?:'—',0,20,'…','UTF-8')
        );
    }
}

function listSimple(PDO $pdo,string $kw,bool $showDate=true){
    $sql="SELECT p1.series, p1.volume, p1.bought_at FROM purchases p1
          INNER JOIN (SELECT series, MAX(volume) AS mv FROM purchases GROUP BY series) p2
          ON p1.series = p2.series AND p1.volume = p2.mv";
    $params=[];
    if($kw){
        $sql .= ' WHERE p1.series LIKE :kw';
        $params[':kw'] = "%$kw%";
    }
    $sql .= ' ORDER BY p1.series';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows){
        echo "(無資料)\n";
        return;
    }
    $gap = str_repeat(' ', 40);
    foreach($rows as $r){
        if($showDate){
            echo "{$r['series']} {$r['volume']} $gap [{$r['bought_at']}]\n";
        }else{
            echo "{$r['series']} {$r['volume']}\n";
        }
    }
}
?>
