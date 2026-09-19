<?php

require_once "auth.php";
require_once __DIR__ . "/config/hotspot.php";






/*
|--------------------------------------------------------------------------
| ROUTEROS API CLIENT SEDERHANA
|--------------------------------------------------------------------------
*/

function mt_write_word($socket, $word)
{
    $length = strlen($word);

    if ($length < 0x80) {
        $encoded = chr($length);
    } elseif ($length < 0x4000) {
        $encoded = chr(($length >> 8) | 0x80)
                 . chr($length & 0xFF);
    } elseif ($length < 0x200000) {
        $encoded = chr(($length >> 16) | 0xC0)
                 . chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
    } elseif ($length < 0x10000000) {
        $encoded = chr(($length >> 24) | 0xE0)
                 . chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
    } else {
        $encoded = chr(0xF0)
                 . chr(($length >> 24) & 0xFF)
                 . chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
    }

    fwrite($socket, $encoded . $word);
}


function mt_read_exact($socket, $length)
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));

        if ($chunk === false || $chunk === '') {
            throw new Exception("Koneksi MikroTik terputus.");
        }

        $data .= $chunk;
    }

    return $data;
}


function mt_read_length($socket)
{
    $first = ord(mt_read_exact($socket, 1));

    if ($first < 0x80) {
        return $first;
    }

    if ($first < 0xC0) {
        $second = ord(mt_read_exact($socket, 1));

        return (($first & 0x3F) << 8) | $second;
    }

    if ($first < 0xE0) {
        $data = mt_read_exact($socket, 2);

        return (($first & 0x1F) << 16)
             | (ord($data[0]) << 8)
             | ord($data[1]);
    }

    if ($first < 0xF0) {
        $data = mt_read_exact($socket, 3);

        return (($first & 0x0F) << 24)
             | (ord($data[0]) << 16)
             | (ord($data[1]) << 8)
             | ord($data[2]);
    }

    if ($first === 0xF0) {
        $data = mt_read_exact($socket, 4);

        return (ord($data[0]) << 24)
             | (ord($data[1]) << 16)
             | (ord($data[2]) << 8)
             | ord($data[3]);
    }

    throw new Exception("Format panjang data API tidak dikenal.");
}


function mt_read_word($socket)
{
    $length = mt_read_length($socket);

    if ($length === 0) {
        return '';
    }

    return mt_read_exact($socket, $length);
}


function mt_write_sentence($socket, array $words)
{
    foreach ($words as $word) {
        mt_write_word($socket, $word);
    }

    // Akhiri sentence
    mt_write_word($socket, '');
}


function mt_read_sentence($socket)
{
    $sentence = [];

    while (true) {
        $word = mt_read_word($socket);

        if ($word === '') {
            break;
        }

        $sentence[] = $word;
    }

    return $sentence;
}


/*
|--------------------------------------------------------------------------
| LOGIN KE MIKROTIK
|--------------------------------------------------------------------------
*/

function mt_login($socket, $username, $password)
{
    mt_write_sentence($socket, [
        '/login',
        '=name=' . $username,
        '=password=' . $password
    ]);

    while (true) {
        $sentence = mt_read_sentence($socket);

        if (empty($sentence)) {
            continue;
        }

        $type = $sentence[0];

        if ($type === '!done') {
            return true;
        }

        if ($type === '!trap' || $type === '!fatal') {
            $message = 'Login MikroTik gagal.';

            foreach ($sentence as $word) {
                if (strpos($word, '=message=') === 0) {
                    $message = substr($word, 9);
                }
            }

            throw new Exception($message);
        }
    }
}


/*
|--------------------------------------------------------------------------
| AMBIL HOTSPOT ACTIVE USER
|--------------------------------------------------------------------------
*/

function mt_get_hotspot_users($socket)
{
    mt_write_sentence($socket, [
        '/ip/hotspot/active/print'
    ]);

    $users = [];

    while (true) {
        $sentence = mt_read_sentence($socket);

        if (empty($sentence)) {
            continue;
        }

        $type = $sentence[0];

        if ($type === '!re') {

            $row = [];

            foreach ($sentence as $word) {

                if (strpos($word, '=') !== 0) {
                    continue;
                }

                $pos = strpos($word, '=');

                if ($pos === false) {
                    continue;
                }

                $word = substr($word, 1);

                $pos = strpos($word, '=');

                if ($pos === false) {
                    continue;
                }

                $key = substr($word, 0, $pos);
                $value = substr($word, $pos + 1);

                $row[$key] = $value;
            }

            $users[] = $row;
        }

        elseif ($type === '!done') {
            break;
        }

        elseif ($type === '!trap' || $type === '!fatal') {

            $message = 'Gagal mengambil data Hotspot.';

            foreach ($sentence as $word) {
                if (strpos($word, '=message=') === 0) {
                    $message = substr($word, 9);
                }
            }

            throw new Exception($message);
        }
    }

    return $users;
}


/*
|--------------------------------------------------------------------------
| TAMBAHAN:
| AMBIL RESOURCE MIKROTIK
|--------------------------------------------------------------------------
*/

function mt_get_system_resource($socket)
{
    mt_write_sentence($socket, [
        '/system/resource/print'
    ]);

    $resource = [];

    while (true) {

        $sentence = mt_read_sentence($socket);

        if (empty($sentence)) {
            continue;
        }

        $type = $sentence[0];

        if ($type === '!re') {

            foreach ($sentence as $word) {

                if (strpos($word, '=') !== 0) {
                    continue;
                }

                $word = substr($word, 1);

                $pos = strpos($word, '=');

                if ($pos === false) {
                    continue;
                }

                $key = substr($word, 0, $pos);
                $value = substr($word, $pos + 1);

                $resource[$key] = $value;
            }
        }

        elseif ($type === '!done') {
            break;
        }

        elseif ($type === '!trap' || $type === '!fatal') {

            $message = 'Gagal mengambil resource MikroTik.';

            foreach ($sentence as $word) {

                if (strpos($word, '=message=') === 0) {

                    $message = substr($word, 9);
                }
            }

            throw new Exception($message);
        }
    }

    return $resource;
}


/*
|--------------------------------------------------------------------------
| TAMBAHAN:
| AMBIL HOST HOTSPOT YANG BELUM LOGIN
|--------------------------------------------------------------------------
*/

function mt_get_hotspot_hosts($socket, $activeUsers)
{
    /*
     * Buat daftar MAC Address user yang sudah LOGIN
     */
    $activeMac = [];

    foreach ($activeUsers as $user) {

        if (!empty($user['mac-address'])) {

            $mac = strtoupper(
                trim($user['mac-address'])
            );

            $activeMac[$mac] = true;
        }
    }


    /*
     * Ambil semua Host Hotspot
     */
    mt_write_sentence($socket, [
        '/ip/hotspot/host/print'
    ]);

    $hosts = [];

    while (true) {

        $sentence = mt_read_sentence($socket);

        if (empty($sentence)) {
            continue;
        }

        $type = $sentence[0];


        if ($type === '!re') {

            $row = [];

            foreach ($sentence as $word) {

                if (strpos($word, '=') !== 0) {
                    continue;
                }

                $word = substr($word, 1);

                $pos = strpos($word, '=');

                if ($pos === false) {
                    continue;
                }

                $key = substr($word, 0, $pos);
                $value = substr($word, $pos + 1);

                $row[$key] = $value;
            }


            /*
             * Ambil MAC Address
             */
            $mac = strtoupper(
                trim($row['mac-address'] ?? '')
            );


            /*
             * Kalau MAC sudah ada di ACTIVE,
             * berarti sudah login.
             *
             * Jangan tampilkan.
             */
            if ($mac !== '' && isset($activeMac[$mac])) {
                continue;
            }


            /*
             * Host tanpa MAC tidak ditampilkan.
             */
            if ($mac === '') {
                continue;
            }


            $hosts[] = $row;
        }


        elseif ($type === '!done') {
            break;
        }


        elseif ($type === '!trap' || $type === '!fatal') {

            $message = 'Gagal mengambil data Hotspot Host.';

            foreach ($sentence as $word) {

                if (strpos($word, '=message=') === 0) {

                    $message = substr(
                        $word,
                        9
                    );
                }
            }

            throw new Exception($message);
        }
    }

    return $hosts;
}


/*
|--------------------------------------------------------------------------
| FORMAT BYTE
|--------------------------------------------------------------------------
*/

function format_bytes($bytes)
{
    $bytes = (float)$bytes;

    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}


/*
|--------------------------------------------------------------------------
| FORMAT SPEED
|--------------------------------------------------------------------------
*/

function format_speed($bps)
{
    $bps = (float)$bps;

    if ($bps >= 1000000000) {
        return number_format($bps / 1000000000, 2) . ' Gbps';
    }

    if ($bps >= 1000000) {
        return number_format($bps / 1000000, 2) . ' Mbps';
    }

    if ($bps >= 1000) {
        return number_format($bps / 1000, 2) . ' Kbps';
    }

    return number_format($bps, 0) . ' bps';
}


/*
|--------------------------------------------------------------------------
| API AJAX
|--------------------------------------------------------------------------
|
| user-hotspot.php?api=1
|
*/

if (isset($_GET['api']) && $_GET['api'] === '1') {

    header('Content-Type: application/json; charset=utf-8');

    $socket = null;

    try {

        $host = $mikrotik['host'];
        $port = (int)$mikrotik['port'];
        $username = $mikrotik['user'];
        $password = $mikrotik['password'];

        $errno = 0;
        $errstr = '';

        $socket = @fsockopen(
            $host,
            $port,
            $errno,
            $errstr,
            5
        );

        if (!$socket) {
            throw new Exception(
                'Tidak bisa terhubung ke MikroTik: ' . $errstr
            );
        }

        stream_set_timeout($socket, 5);

        mt_login(
            $socket,
            $username,
            $password
        );

        $users = mt_get_hotspot_users($socket);


        /*
         * TAMBAHAN:
         * Ambil Host yang belum login
         */
        $hosts = mt_get_hotspot_hosts(
            $socket,
            $users
        );


        /*
         * TAMBAHAN:
         * Ambil Resource MikroTik
         */
        $resource = mt_get_system_resource($socket);


        fclose($socket);

        echo json_encode([
            'status' => 'success',
            'time' => date('Y-m-d H:i:s'),

            'count' => count($users),

            'users' => $users,

            /*
             * TAMBAHAN
             */
            'host_count' => count($hosts),
            'hosts' => $hosts,

            /*
             * TAMBAHAN:
             * RESOURCE MIKROTIK
             */
            'resource' => $resource
        ]);

        exit;

    } catch (Throwable $e) {

        if (is_resource($socket)) {
            fclose($socket);
        }

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| HALAMAN WEB
|--------------------------------------------------------------------------
*/

?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>User Hotspot MikroTik</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f6f8;
    color: #222;
}

.container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.title h1 {
    margin: 0;
    font-size: 28px;
}

.title p {
    margin: 6px 0 0;
    color: #777;
}

.status {
    padding: 10px 16px;
    border-radius: 8px;
    background: #ddd;
    font-weight: bold;
}

.online {
    background: #dff7e5;
    color: #16833b;
}

.offline {
    background: #ffe0e0;
    color: #b00020;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(0,0,0,.08);
    margin-bottom: 20px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.stat {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 18px;
}

.stat-label {
    color: #777;
    font-size: 14px;
}

.stat-value {
    font-size: 28px;
    font-weight: bold;
    margin-top: 5px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    white-space: nowrap;
}

th {
    background: #f5f5f5;
}

tr:hover {
    background: #fafafa;
}

.speed {
    font-weight: bold;
}

.download {
    color: #16833b;
}

.upload {
    color: #1769aa;
}

.search {
    width: 100%;
    max-width: 400px;
    padding: 11px 14px;
    border: 1px solid #ccc;
    border-radius: 7px;
    margin-bottom: 15px;
    font-size: 14px;
}

.small {
    color: #777;
    font-size: 13px;
}

.error {
    color: #b00020;
    font-weight: bold;
}

@media (max-width: 800px) {

    .stats {
        grid-template-columns: 1fr;
    }

    .header {
        align-items: flex-start;
        flex-direction: column;
    }

}

/* tombol script */

.keluar {
    /* Logout */
    color : #fff;
    background-color: #dc3545;
}

.biru {
    /* Warna Tema Primary (#0d6efd) */
    color: #fff;
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.btn {
    /* Tampilan dasar mirip Bootstrap */
    display: inline-block;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    text-align: center;
    text-decoration: none;
    vertical-align: middle;
    cursor: pointer;
    user-select: none;

    /* Padding & Border Radius khas Bootstrap */
    padding: 0.375rem 0.75rem;
    border: 1px solid transparent;
    border-radius: 0.375rem;

    /* Transisi halus saat di-hover / fokus */
    transition: color 0.15s ease-in-out,
                background-color 0.15s ease-in-out,
                border-color 0.15s ease-in-out,
                box-shadow 0.15s ease-in-out;
}


/*
|--------------------------------------------------------------------------
| TAMBAHAN:
| STATUS BELUM LOGIN
|--------------------------------------------------------------------------
*/

.belum-login {
    color: #d97706;
    font-weight: bold;
}

.host-card-title {
    margin-top: 0;
}


/*
|--------------------------------------------------------------------------
| END CSS
|--------------------------------------------------------------------------
*/

</style>

</head>

<body>

<div class="container">

    <div class="header">

        <div class="title">

            <h1>🌐 User Hotspot Aktif Lantai 1</h1>

            <p>Monitoring user Hotspot MikroTik Lantai 1</p>

            <br/>

            <!-- TOMBOL -->
            <a href="index.php" class="btn biru">Beranda</a>
            <a href="queue.php" class="btn biru">Monitor Queue</a>
            <a href="logout.php" class="btn keluar"><?= htmlspecialchars($_SESSION["username"]) ?> Keluar</a>

        </div>

        <div id="status" class="status">
            MENGHUBUNGKAN...
        </div>

    </div>


    <!-- ===================================================== -->
    <!-- TAMBAHAN: RESOURCE MIKROTIK -->
    <!-- ===================================================== -->

    <div class="stats">

        <div class="card stat">

            <div class="stat-label">
                Perangkat
            </div>

            <div class="stat-value"
                 id="routerDevice"
                 style="font-size:22px;">
                Mikrotik RB 750
            </div>

        </div>


        <div class="card stat">

            <div class="stat-label">
                RAM
            </div>

            <div class="stat-value"
                 id="routerRam"
                 style="font-size:22px;">
                0 / 0 MB
            </div>

            <div class="small"
                 id="routerRamUsage">
                Usage: 0%
            </div>

        </div>


        <div class="card stat">

            <div class="stat-label">
                CPU
            </div>

            <div class="stat-value"
                 id="routerCpu"
                 style="font-size:28px;">
                0%
            </div>

        </div>

    </div>


    <div class="stats">
        <div class="card stat">

            <div class="stat-label">
                User Online
            </div>

            <div class="stat-value" id="userCount">
                0
            </div>

        </div>


        <div class="card stat">

            <div class="stat-label">
                Download Total
            </div>

            <div class="stat-value" id="totalDownload">
                0 bps
            </div>

        </div>


        <div class="card stat">

            <div class="stat-label">
                Upload Total
            </div>

            <div class="stat-value" id="totalUpload">
                0 bps
            </div>

        </div>

    </div>


    <!-- ===================================================== -->
    <!-- USER AKTIF -->
    <!-- ===================================================== -->

    <div class="card">

        <input
            type="text"
            id="search"
            class="search"
            placeholder="🔎 Cari username / IP / MAC..."
        >

        <div class="small" id="lastUpdate">
            Menunggu data...
        </div>

        <br>


        USER AKTIF <br/>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>No</th>
                        <th>Username</th>
                        <th>IP Address</th>
                        <th>MAC Address</th>
                        <th>Uptime</th>
                        <th>Upload</th>
                        <th>Download</th>
                        <th>Total Upload</th>
                        <th>Total Download</th>

                    </tr>

                </thead>

                <tbody id="userTable">

                    <tr>
                        <td colspan="9">
                            Mengambil data...
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>


    <!-- ===================================================== -->
    <!-- TAMBAHAN: HOST BELUM LOGIN -->
    <!-- ===================================================== -->

    <div class="card">

        <h3 class="host-card-title">
            📱 Device Belum Login
        </h3>

        <div class="small" id="hostInfo">
            Menunggu data...
        </div>

        <br>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>No</th>
                        <th>IP Address</th>
                        <th>MAC Address</th>
                        <th>Host</th>
                        <th>Server</th>
                        <th>Status</th>

                    </tr>

                </thead>

                <tbody id="hostTable">

                    <tr>

                        <td colspan="6">
                            Mengambil data...
                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>

let previousData = {};
let previousTime = Date.now();

let latestUsers = [];


/*
|--------------------------------------------------------------------------
| TAMBAHAN:
| DATA HOST BELUM LOGIN
|--------------------------------------------------------------------------
*/

let latestHosts = [];


/*
|--------------------------------------------------------------------------
| FORMAT BYTE
|--------------------------------------------------------------------------
*/

function formatBytes(bytes)
{
    bytes = Number(bytes || 0);

    if (bytes >= 1024 * 1024 * 1024) {
        return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }

    if (bytes >= 1024 * 1024) {
        return (bytes / 1024 / 1024).toFixed(2) + ' MB';
    }

    if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    }

    return Math.round(bytes) + ' B';
}


/*
|--------------------------------------------------------------------------
| FORMAT SPEED
|--------------------------------------------------------------------------
*/

function formatSpeed(bps)
{
    bps = Number(bps || 0);

    if (bps >= 1000000000) {
        return (bps / 1000000000).toFixed(2) + ' Gbps';
    }

    if (bps >= 1000000) {
        return (bps / 1000000).toFixed(2) + ' Mbps';
    }

    if (bps >= 1000) {
        return (bps / 1000).toFixed(2) + ' Kbps';
    }

    return Math.round(bps) + ' bps';
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA
|--------------------------------------------------------------------------
*/

async function loadUsers()
{
    try {

        const response = await fetch(
            'user-hotspot.php?api=1&_=' + Date.now()
        );

        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'Gagal mengambil data');
        }

        document.getElementById('status').textContent =
            '● ONLINE';

        document.getElementById('status').className =
            'status online';

        latestUsers = data.users || [];


        /*
         * TAMBAHAN:
         * RESOURCE MIKROTIK
         */

        const resource = data.resource || {};


        /*
         * CPU
         */

        const cpuLoad =
            Number(resource['cpu-load'] || 0);

        document.getElementById('routerCpu').textContent =
            cpuLoad + '%';


        /*
         * RAM
         */

        const totalMemory =
            Number(resource['total-memory'] || 0);

        const freeMemory =
            Number(resource['free-memory'] || 0);

        const usedMemory =
            Math.max(
                0,
                totalMemory - freeMemory
            );


        let ramUsage = 0;

        if (totalMemory > 0) {

            ramUsage =
                (usedMemory / totalMemory) * 100;
        }


        /*
         * Konversi Byte -> MB
         */

        const usedMB =
            usedMemory / 1024 / 1024;

        const totalMB =
            totalMemory / 1024 / 1024;


        document.getElementById('routerRam').textContent =
            usedMB.toFixed(1) +
            ' / ' +
            totalMB.toFixed(1) +
            ' MB';


        document.getElementById('routerRamUsage').textContent =
            'Usage: ' +
            ramUsage.toFixed(1) +
            '%';


        /*
         * TAMBAHAN:
         * Ambil data Host belum login
         */
        latestHosts = data.hosts || [];


        document.getElementById('userCount').textContent =
            latestUsers.length;

        const now = Date.now();

        const elapsed =
            (now - previousTime) / 1000;

        let totalDownload = 0;
        let totalUpload = 0;

        latestUsers.forEach(function(user, index) {

            /*
             * RouterOS:
             *
             * bytes-in  = traffic dari client
             * bytes-out = traffic menuju client
             *
             * Kita tampilkan:
             *
             * bytes-in  -> Upload
             * bytes-out -> Download
             */

            const key =
                (user['.id'] || '') + '_' +
                (user['user'] || '') + '_' +
                (user['address'] || '');

            const bytesIn =
                Number(user['bytes-in'] || 0);

            const bytesOut =
                Number(user['bytes-out'] || 0);

            let uploadBps = 0;
            let downloadBps = 0;

            if (previousData[key]) {

                const oldIn =
                    Number(previousData[key].bytesIn || 0);

                const oldOut =
                    Number(previousData[key].bytesOut || 0);

                if (elapsed > 0) {

                    uploadBps =
                        Math.max(
                            0,
                            ((bytesIn - oldIn) * 8) / elapsed
                        );

                    downloadBps =
                        Math.max(
                            0,
                            ((bytesOut - oldOut) * 8) / elapsed
                        );

                }

            }

            user._uploadBps = uploadBps;
            user._downloadBps = downloadBps;

            totalUpload += uploadBps;
            totalDownload += downloadBps;

            previousData[key] = {
                bytesIn: bytesIn,
                bytesOut: bytesOut
            };

        });


        previousTime = now;


        document.getElementById('totalDownload').textContent =
            formatSpeed(totalDownload);

        document.getElementById('totalUpload').textContent =
            formatSpeed(totalUpload);


        renderTable();


        /*
         * TAMBAHAN:
         * Tampilkan Host belum login
         */
        renderHostTable();


        document.getElementById('lastUpdate').textContent =
            'Update: ' + new Date().toLocaleTimeString('id-ID');

    }

    catch (error) {

        console.error(error);

        document.getElementById('status').textContent =
            '● OFFLINE';

        document.getElementById('status').className =
            'status offline';

        document.getElementById('userTable').innerHTML =
            '<tr><td colspan="9" class="error">' +
            error.message +
            '</td></tr>';


        /*
         * TAMBAHAN:
         * Kalau koneksi gagal
         */
        document.getElementById('hostTable').innerHTML =
            '<tr><td colspan="6" class="error">' +
            error.message +
            '</td></tr>';

    }
}


/*
|--------------------------------------------------------------------------
| TAMPILKAN TABLE USER AKTIF
|--------------------------------------------------------------------------
*/

function renderTable()
{
    const keyword =
        document.getElementById('search').value
        .toLowerCase()
        .trim();

    const table =
        document.getElementById('userTable');

    const filtered =
        latestUsers.filter(function(user) {

            const text =
                [
                    user['user'],
                    user['address'],
                    user['mac-address']
                ]
                .join(' ')
                .toLowerCase();

            return text.includes(keyword);

        });


    if (filtered.length === 0) {

        table.innerHTML =
            '<tr>' +
            '<td colspan="9">' +
            'Tidak ada user aktif.' +
            '</td>' +
            '</tr>';

        return;
    }


    let html = '';


    filtered.forEach(function(user, index) {

        html += '<tr>';

        html += '<td>' +
            (index + 1) +
            '</td>';

        html += '<td><strong>' +
            escapeHtml(user['user'] || '-') +
            '</strong></td>';

        html += '<td>' +
            escapeHtml(user['address'] || '-') +
            '</td>';

        html += '<td>' +
            escapeHtml(user['mac-address'] || '-') +
            '</td>';

        html += '<td>' +
            escapeHtml(user['uptime'] || '-') +
            '</td>';

        html += '<td class="speed upload">' +
            formatSpeed(user._uploadBps || 0) +
            '</td>';

        html += '<td class="speed download">' +
            formatSpeed(user._downloadBps || 0) +
            '</td>';

        html += '<td>' +
            formatBytes(user['bytes-in'] || 0) +
            '</td>';

        html += '<td>' +
            formatBytes(user['bytes-out'] || 0) +
            '</td>';

        html += '</tr>';

    });


    table.innerHTML = html;
}


/*
|--------------------------------------------------------------------------
| TAMBAHAN:
| TAMPILKAN HOST BELUM LOGIN
|--------------------------------------------------------------------------
*/

function renderHostTable()
{
    const table =
        document.getElementById('hostTable');

    const info =
        document.getElementById('hostInfo');


    /*
     * Tidak ada Host belum login
     */
    if (latestHosts.length === 0) {

        table.innerHTML =
            '<tr>' +
            '<td colspan="6" style="text-align:center;">' +
            'Tidak ada device yang belum login.' +
            '</td>' +
            '</tr>';

        info.textContent =
            '0 device belum login';

        return;
    }


    /*
     * Jumlah device
     */
    info.textContent =
        latestHosts.length +
        ' device terdeteksi tetapi belum login';


    let html = '';


    latestHosts.forEach(function(host, index) {

        html += '<tr>';


        /*
         * NO
         */
        html += '<td>' +
            (index + 1) +
            '</td>';


        /*
         * IP ADDRESS
         */
        html += '<td>' +
            escapeHtml(
                host['address'] || '-'
            ) +
            '</td>';


        /*
         * MAC ADDRESS
         */
        html += '<td>' +
            escapeHtml(
                host['mac-address'] || '-'
            ) +
            '</td>';


        /*
         * HOST
         */
        html += '<td>' +
            escapeHtml(
                host['host'] ||
                host['hostname'] ||
                '-'
            ) +
            '</td>';


        /*
         * SERVER
         */
        html += '<td>' +
            escapeHtml(
                host['server'] || '-'
            ) +
            '</td>';


        /*
         * STATUS
         */
        html += '<td class="belum-login">' +
            'BELUM LOGIN' +
            '</td>';


        html += '</tr>';

    });


    table.innerHTML = html;
}


/*
|--------------------------------------------------------------------------
| CEGAH HTML INJECTION
|--------------------------------------------------------------------------
*/

function escapeHtml(value)
{
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

document.getElementById('search')
    .addEventListener('input', renderTable);


/*
|--------------------------------------------------------------------------
| REFRESH SETIAP 1 DETIK
|--------------------------------------------------------------------------
*/

loadUsers();

setInterval(loadUsers, 1000);

</script>


</body>

</html>