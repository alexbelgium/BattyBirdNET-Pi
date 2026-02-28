<?php
/* Basic input sanitation */
$_GET  = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
$_POST = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];

error_reporting(E_ERROR);
ini_set('display_errors', 1);

$user = trim(shell_exec("awk -F: '/1000/{print \$1}' /etc/passwd"));
$home = trim(shell_exec("awk -F: '/1000/{print \$6}' /etc/passwd"));

if (file_exists($home.'/BirdNET-Pi/scripts/thisrun.txt')) {
  $config = parse_ini_file($home.'/BirdNET-Pi/scripts/thisrun.txt');
} elseif (file_exists('./scripts/thisrun.txt')) {
  $config = parse_ini_file('./scripts/thisrun.txt');
} elseif (file_exists('./scripts/firstrun.ini')) {
  $config = parse_ini_file('./scripts/firstrun.ini');
}

/* Authentication check for direct AJAX calls */
function ensure_species_tools_auth() {
  global $config;
  $caddypwd = $config['CADDY_PWD'];
  if (!isset($_SERVER['PHP_AUTH_USER']) ||
      $_SERVER['PHP_AUTH_PW'] !== $caddypwd ||
      $_SERVER['PHP_AUTH_USER'] !== 'birdnet') {
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
  }
}

/* ---------- disk species counts (AJAX endpoint) ---------- */
if (isset($_GET['diskcounts'])) {
    ensure_species_tools_auth();
    header('Content-Type: application/json');
    $script = __DIR__ . '/disk_species_count.sh';
    $cmd    = 'HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($script) . ' 2>&1';
    $output = @shell_exec($cmd);
    $counts = [];
    if ($output !== null) {
        foreach (preg_split('/\\r?\\n/', $output) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)(k?)\\s*:\\s*(.+)$/i', $line, $m)) {
                $num = (float)$m[1];
                if (strtolower($m[2]) === 'k') $num *= 1000;
                $counts[$m[3]] = (int)round($num);
            }
        }
    }
    echo json_encode($counts, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- DB open (RO unless deleting) ---------- */
$db_path = file_exists(__DIR__ . '/birds.db') ? __DIR__ . '/birds.db' : './scripts/birds.db';
$flags = isset($_GET['delete']) ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY;
$db   = new SQLite3($db_path, $flags);
$db->busyTimeout(1000);

/* Paths / lists */
$base_symlink   = $home . '/BirdSongs/Extracted/By_Date';
$base           = realpath($base_symlink);

$scripts_dir    = __DIR__;
$exclude_file   = $scripts_dir . '/exclude_species_list.txt';
$whitelist_file = $scripts_dir . '/whitelist_species_list.txt';

foreach ([$exclude_file, $whitelist_file] as $file) {
    if (!file_exists($file)) touch($file);
}

$excluded_species = file_exists($exclude_file) ? array_map(function($l) { return explode('_', trim($l), 2)[0]; }, file($exclude_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$whitelisted_species = file_exists($whitelist_file) ? array_map(function($l) { return explode('_', trim($l), 2)[0]; }, file($whitelist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];

$sf_thresh = isset($config['SF_THRESH']) ? (float)$config['SF_THRESH'] : 0.0;

/* ---------- helpers ---------- */
function join_path() { $parts = func_get_args(); return preg_replace('#/+#', '/', implode('/', $parts)); }
function can_unlink($p) { return is_link($p) || is_file($p); }

function ensure_db_ok_species($stmt) {
  if ($stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}

/* Collect files/dirs for a species */
function collect_species_targets($db, $species, $home, $base) {
  $stmt = $db->prepare('SELECT Date, Com_Name, Sci_Name, File_Name FROM detections WHERE Sci_Name = :name');
  ensure_db_ok_species($stmt);
  $stmt->bindValue(':name', $species, SQLITE3_TEXT);
  $res = $stmt->execute();

  $count = 0; $files = []; $dirs = []; $sci = null;
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $count++; if ($sci === null) $sci = $row['Sci_Name'];
    $dir = str_replace([' ', "'"], ['_', ''], $row['Com_Name']);
    $candidates = [
      join_path($home, 'BirdSongs/Extracted/By_Date',         $row['Date'], $dir, $row['File_Name']),
      join_path($home, 'BirdSongs/Extracted/By_Date/shifted', $row['Date'], $dir, $row['File_Name']),
    ];
    foreach ($candidates as $c) {
      if (can_unlink($c)) { $files[$c] = true; $dirs[] = dirname($c); continue; }
      $d = realpath(dirname($c));
      if ($d !== false) {
        $alt = $d . DIRECTORY_SEPARATOR . basename($c);
        if (can_unlink($alt)) { $files[$alt] = true; $dirs[] = dirname($alt); }
      }
    }
  }
  return ['count'=>$count, 'files'=>array_keys($files), 'dirs'=>array_values(array_unique($dirs)), 'sci'=>$sci];
}

/* ---------- toggle exclude/whitelist ---------- */
if (isset($_GET['toggle'], $_GET['species'], $_GET['action'])) {
  ensure_species_tools_auth();
  $list    = $_GET['toggle'];
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);

  if     ($list === 'exclude')   { $file = $exclude_file; }
  elseif ($list === 'whitelist') { $file = $whitelist_file; }
  else { header('Content-Type: text/plain'); echo 'Invalid list type'; exit; }

  $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  if ($_GET['action'] === 'add') {
    if (!in_array($species, $lines, true)) $lines[] = $species;
  } else {
    $lines = array_values(array_filter($lines, function($l) use ($species) { return $l !== $species; }));
  }
  file_put_contents($file, implode("\n", $lines) . (empty($lines) ? "" : "\n"));
  header('Content-Type: text/plain'); echo 'OK'; exit;
}

/* ---------- count ---------- */
if (isset($_GET['getcounts'])) {
  ensure_species_tools_auth();
  header('Content-Type: application/json');
  if ($base === false) { http_response_code(500); exit(json_encode(['error' => 'Base directory not found'])); }
  $species = htmlspecialchars_decode($_GET['getcounts'], ENT_QUOTES);
  $info = collect_species_targets($db, $species, $home, $base);
  echo json_encode(['count' => $info['count'], 'files' => count($info['files'])]); exit;
}

/* ---------- delete ---------- */
if (isset($_GET['delete'])) {
  ensure_species_tools_auth();
  header('Content-Type: application/json');
  if ($base === false) { http_response_code(500); exit(json_encode(['error' => 'Base directory not found'])); }
  $species = htmlspecialchars_decode($_GET['delete'], ENT_QUOTES);
  $info = collect_species_targets($db, $species, $home, $base);
  $deleted = count($info['files']);
  foreach ($info['dirs'] as $dir) {
    $escaped_dir = escapeshellarg($dir);
    exec("sudo rm -r $escaped_dir 2>&1", $output, $retval);
    if ($retval !== 0) {
      echo json_encode(['error' => 'Files deletion failed: ' . implode(", ", $output)]);
      exit;
    }
  }
  $del = $db->prepare('DELETE FROM detections WHERE Sci_Name = :name');
  ensure_db_ok_species($del);
  $del->bindValue(':name', $species, SQLITE3_TEXT);
  $del->execute();
  $lines_deleted = $db->changes();

  echo json_encode(['lines' => $lines_deleted, 'files' => $deleted]); exit;
}

/* ---------- query species aggregates ---------- */
$sql = <<<SQL
SELECT Com_Name, Sci_Name, COUNT(*) AS Count, MAX(Confidence) AS MaxConfidence, MAX(Date) AS LastSeen
FROM detections
GROUP BY Sci_Name;
SQL;
$result = $db->query($sql);
?>
<style>
  .circle-icon{display:inline-block;width:12px;height:12px;border:1px solid #777;border-radius:50%;cursor:pointer;}
  .centered{max-width:1100px;margin:0 auto}
  #speciesTable th{cursor:pointer}
  .toolbar{display:flex;gap:8px;align-items:center;margin:8px 0}
  .toolbar input[type="text"]{padding:6px 8px;min-width:260px}
  #speciesTable a,
  #speciesTable a:visited,
  #speciesTable a:active {
    color: black;
    text-decoration: none;
  }
</style>

<div class="centered">
  <!-- Search with persistence -->
  <div class="toolbar">
    <input id="q" type="text" placeholder="Filter species… (name, scientific)" title="Type to filter; persists across reloads">
    <small id="matchCount"></small>
  </div>

  <table id="speciesTable">
    <thead>
      <tr>
        <th onclick="sortTable(0)">Common Name</th>
        <th onclick="sortTable(1)">Scientific Name</th>
        <th>Stats</th>
        <th onclick="sortTable(3)">Count</th>
        <th onclick="sortTable(4)">Max Confidence</th>
        <th onclick="sortTable(5)">Last Seen</th>
        <th onclick="sortTable(6)">Excluded</th>
        <th onclick="sortTable(7)">Whitelisted</th>
        <th>Delete</th>
      </tr>
    </thead>
    <tbody>
<?php while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $common = htmlspecialchars($row['Com_Name'], ENT_QUOTES);
  $scient = htmlspecialchars($row['Sci_Name'], ENT_QUOTES);
  $count  = (int)$row['Count'];
  $max_confidence = round((float)$row['MaxConfidence'] * 100, 1);
  $identifier = $row['Sci_Name'].'_'.$row['Com_Name'];
  $identifier_sci = $row['Sci_Name'];

  $lastSeen = $row['LastSeen'] ?? '';
  $lastSeenSort = $lastSeen ? (strtotime($lastSeen) ?: 0) : 0;

  $common_link = "<a href='views.php?view=Recordings&species=" . rawurlencode($row['Sci_Name']) . "'>" . $common . "</a>";

  $is_excluded    = in_array($identifier_sci, $excluded_species, true);
  $is_whitelisted = in_array($identifier_sci, $whitelisted_species, true);

  $comnamegraph = str_replace("'", "\'", $row['Com_Name']);
  $chart_cell = sprintf("<img style='height: 1em;cursor:pointer;float:unset;display:inline' title='View species stats' onclick=\"generateMiniGraph(this, '%s', 180)\" width=25 src='images/chart.svg'>", $comnamegraph);

  $identifier_js = addslashes($identifier);
  $identifier_sci_js = addslashes($identifier_sci);

  $excl_cell = $is_excluded
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' onclick=\"toggleSpecies('exclude','{$identifier_js}','del')\">"
    : "<span class='circle-icon' onclick=\"toggleSpecies('exclude','{$identifier_js}','add')\"></span>";

  $white_cell = $is_whitelisted
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' onclick=\"toggleSpecies('whitelist','{$identifier_js}','del')\">"
    : "<span class='circle-icon' onclick=\"toggleSpecies('whitelist','{$identifier_js}','add')\"></span>";

  echo "<tr data-comname=\"{$common}\" data-sciname=\"{$scient}\">"
     . "<td>{$common_link}</td>"
     . "<td><i>{$scient}</i></td>"
     . "<td>{$chart_cell}</td>"
     . "<td>{$count}</td>"
     . "<td data-sort='{$max_confidence}'>{$max_confidence}%</td>"
     . "<td data-sort=\"{$lastSeenSort}\">{$lastSeen}</td>"
     . "<td data-sort='".($is_excluded?0:1)."'>".$excl_cell."</td>"
     . "<td data-sort='".($is_whitelisted?0:1)."'>".$white_cell."</td>"
     . "<td><img style='cursor:pointer;max-width:20px' src='images/delete.svg' onclick=\"deleteSpecies('".addslashes($row['Sci_Name'])." + ".addslashes($row['Com_Name'])."')\"></td>"
     . "</tr>";
} ?>
    </tbody>
  </table>
</div>
<script src="static/Chart.bundle.js"></script>
<script>
const scriptsBase = 'scripts/';

const get = (url) => fetch(url, {cache:'no-store'}).then(r => r.text());

/* ---------- Files on Disk column auto-load ---------- */
function addDiskCounts() {
  return get(scriptsBase + 'species_tools.php?diskcounts=1').then(t => {
    let counts; try { counts = JSON.parse(t); } catch { console.warn('Could not parse disk counts'); return; }

    const table = document.getElementById('speciesTable');
    const headerRow = table.tHead.rows[0];

    // Insert header before last column (Delete)
    const deleteHeader = headerRow.lastElementChild;
    const th = document.createElement('th');
    th.textContent = 'Files on Disk';
    headerRow.insertBefore(th, deleteHeader);

    const colIndex = headerRow.cells.length - 2;
    th.addEventListener('click', () => sortTable(colIndex));

    const decoder = document.createElement('textarea');
    document.querySelectorAll('#speciesTable tbody tr').forEach(tr => {
      decoder.innerHTML = tr.getAttribute('data-comname') || '';
      const name = decoder.value;
      const lookup = name.replace(/'/g, '');
      const count = counts[lookup] || 0;
      const td = document.createElement('td');
      td.textContent = count;
      td.dataset.sort = count;
      tr.insertBefore(td, tr.lastElementChild);
    });
  }).catch(() => {
    console.warn('Disk counts load failed.');
  });
}

window.addEventListener('scroll', function() {
  var charts = document.querySelectorAll('.chartdiv');
  charts.forEach(function(chart) {
    chart.parentNode.removeChild(chart);
    window.chartWindow = undefined;
  });
});

/* ---------- toggles / delete ---------- */
function toggleSpecies(list, species, action) {
  get(scriptsBase + 'species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action)
    .then(t => { if (t.trim() === 'OK') location.reload(); });
}
function deleteSpecies(species) {
  let parts = species.split(' + '); let sci_species = parts[0]; let com_species = parts[1];
  get(scriptsBase + 'species_tools.php?getcounts=' + encodeURIComponent(sci_species)).then(t => {
    let info; try { info = JSON.parse(t); } catch { alert('Could not parse count response'); return; }
    if (!confirm('Delete ' + info.count + ' detections and local audio and png files for ' + com_species + '?')) return;
    get(scriptsBase + 'species_tools.php?delete=' + encodeURIComponent(sci_species)).then(t2 => {
      try { const res = JSON.parse(t2); alert('Deleted ' + res.lines + ' detections and ' + res.files + ' files for ' + com_species); }
      catch { alert('Deletion complete'); }
      location.reload();
    });
  });
}

/* ---------- Sorting with persistence ---------- */
function sortTable(n) {
  const table = document.getElementById('speciesTable');
  const tbody = table.tBodies[0];
  const rows = Array.from(tbody.rows);
  const asc = table.getAttribute('data-sort-' + n) !== 'asc';
  rows.sort((a, b) => {
    let x = a.cells[n].dataset.sort ?? a.cells[n].innerText.toLowerCase();
    let y = b.cells[n].dataset.sort ?? b.cells[n].innerText.toLowerCase();
    const nx = parseFloat(x), ny = parseFloat(y);
    if (!Number.isNaN(nx) && !Number.isNaN(ny)) { x = nx; y = ny; }
    return (x < y ? (asc ? -1 : 1) : (x > y ? (asc ? 1 : -1) : 0));
  });
  rows.forEach(r => tbody.appendChild(r));
  table.setAttribute('data-sort-' + n, asc ? 'asc' : 'desc');
  try { localStorage.setItem('speciesSortCol', String(n)); localStorage.setItem('speciesSortAsc', asc ? '1' : '0'); } catch(e){}
}
function applySavedSort() {
  const table = document.getElementById('speciesTable');
  const col = parseInt(localStorage.getItem('speciesSortCol') || '', 10);
  const asc = localStorage.getItem('speciesSortAsc');
  if (!Number.isFinite(col)) return;
  sortTable(col);
  const isAscNow = table.getAttribute('data-sort-' + col) === 'asc';
  if ((asc === '1') !== isAscNow) sortTable(col);
}

/* ---------- Search with persistence ---------- */
const q = document.getElementById('q');
const matchCount = document.getElementById('matchCount');
function applyFilter() {
  const needle = (q.value || '').trim().toLowerCase();
  let shown = 0, total = 0;
  document.querySelectorAll('#speciesTable tbody tr').forEach(tr => {
    total++;
    const txt = tr.innerText.toLowerCase();
    const vis = txt.includes(needle);
    tr.style.display = vis ? '' : 'none';
    if (vis) shown++;
  });
  matchCount.textContent = total ? `${shown} / ${total}` : '';
  try { localStorage.setItem('speciesFilter', q.value); } catch(e){}
}
q.addEventListener('input', applyFilter);

/* ---------- Mini graph for species ---------- */
function generateMiniGraph(element, comName, days) {
  if (window.chartWindow) {
    window.chartWindow.parentNode.removeChild(window.chartWindow);
    window.chartWindow = undefined;
  }
  var div = document.createElement('div');
  div.className = 'chartdiv';
  div.style.position = 'fixed';
  div.style.top = '50%';
  div.style.left = '50%';
  div.style.transform = 'translate(-50%, -50%)';
  div.style.background = 'white';
  div.style.border = '2px solid #333';
  div.style.borderRadius = '8px';
  div.style.padding = '16px';
  div.style.zIndex = '9999';
  div.style.minWidth = '400px';
  div.style.maxWidth = '90vw';
  var canvas = document.createElement('canvas');
  canvas.id = 'miniChart';
  div.appendChild(canvas);
  var closeBtn = document.createElement('button');
  closeBtn.textContent = 'Close';
  closeBtn.style.marginTop = '8px';
  closeBtn.onclick = function() { div.parentNode.removeChild(div); window.chartWindow = undefined; };
  div.appendChild(closeBtn);
  document.body.appendChild(div);
  window.chartWindow = div;

  var end = new Date();
  var start = new Date();
  start.setDate(start.getDate() - days);
  var labels = [];
  var dataPoints = [];
  for (var d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    labels.push(d.toISOString().slice(0, 10));
    dataPoints.push(0);
  }

  var ctx = canvas.getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: { labels: labels, datasets: [{ label: comName + ' (last ' + days + ' days)', data: dataPoints, backgroundColor: 'rgba(54, 162, 235, 0.5)' }] },
    options: { responsive: true, scales: { yAxes: [{ ticks: { beginAtZero: true } }] } }
  });
}

/* ---------- boot ---------- */
document.addEventListener('DOMContentLoaded', () => {
  try { const saved = localStorage.getItem('speciesFilter'); if (saved !== null) q.value = saved; } catch(e){}
  applyFilter();
  applySavedSort();
  addDiskCounts();
});
</script>
