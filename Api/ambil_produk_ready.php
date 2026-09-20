<?php

header("Content-Type: application/json; charset=UTF-8");

$url = "https://portalpulsa.com/api/connect/";

$headers = [
    "portal-userid: P176707",
    "portal-key: 014e6ff89d2e0f4c5bcd5320ebc4e780",
    "portal-secret: 1a7374c591f83382fbb249c593bcc30af669790be92b3121f590d0f28c1ba615"
];

$post = [
    "inquiry" => "HARGA",
    "code" => "PULSA"
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_TIMEOUT => 60
]);

$result = curl_exec($ch);

if (curl_errno($ch)) {
    die(json_encode([
        "status" => false,
        "error" => curl_error($ch)
    ]));
}

curl_close($ch);

$json = json_decode($result, true);

if (!isset($json['result']) || $json['result'] !== 'success') {
    die(json_encode($json));
}

/* =========================================================
   🔥 SETTING HARGA (MARKUP)
========================================================= */

$markupDefault = 0;

$markup = [
    "pulsa" => [
        "TELKOMSEL"        => 0,
        "TELKOMSEL PROMO"  => -500,
        "TELKOMSEL BY.U"   => 0,
        "XL"               => 1000,
        "INDOSAT"          => 0,
        "AXIS"             => 0,
        "TRI"              => 0,
    ],
    "data" => [
        "TELKOMSEL"        => 0,
        "TELKOMSEL PROMO"  => 0,
        "TELKOMSEL BY.U"   => 0,
        "XL"               => 1000,
        "INDOSAT"          => 0,
        "AXIS"             => 0,
        "TRI"              => 0,
    ],
    "nelpon" => [
        "TELKOMSEL"        => 2500,
        "XL"               => 2000,
        "INDOSAT"          => 2000,
        "AXIS"             => 1800,
        "TRI"              => 1800,
    ],
    "sms" => [
        "TELKOMSEL"        => 2500,
        "XL"               => 2000,
        "INDOSAT"          => 2000,
        "AXIS"             => 1800,
        "TRI"              => 1800,
    ],
    "masa aktif" => [
        "TELKOMSEL"        => 2500,
        "XL"               => 2000,
        "INDOSAT"          => 2000,
        "AXIS"             => 1800,
        "TRI"              => 1800,
    ],
    "pulsa transfer" => [
        "TELKOMSEL"        => 2500,
        "XL"               => 2000,
        "INDOSAT"          => 2000,
        "AXIS"             => 1800,
        "TRI"              => 1800,
    ],
    "nominal_bonus" => [
        "5000"  => 500,
        "10000" => 800,
        "20000" => 1000,
        "50000" => 1500,
        "100000"=> 2000
    ]
];

/* ========================================================= */

$produkData = [
    "pulsa" => [],
    "data" => [],
    "nelpon" => [],
    "sms" => [],
    "masa aktif" => [],
    "pulsa transfer" => []
];

foreach ($json['message'] as $item) {

    if (($item['status'] ?? '') !== 'normal') continue;

    $operator = strtoupper($item['operator'] ?? '');
    $providerSub = strtoupper($item['provider_sub'] ?? '');
    $desc = strtoupper($item['description'] ?? '');

    /* ---------------- 1. DETEKSI KATEGORI ---------------- */

    $kategori = "pulsa";

    if (strpos($providerSub, "INTERNET") !== false || strpos($operator, "DATA") !== false || strpos($desc, "KUOTA") !== false) {
        $kategori = "data";
    }
    if (strpos($providerSub, "SMS") !== false) {
        $kategori = "sms";
    }
    if (strpos($providerSub, "TELPON") !== false || strpos($providerSub, "NELPON") !== false) {
        $kategori = "nelpon";
    }
    if (strpos($desc, "MASA AKTIF") !== false) {
        $kategori = "masa aktif";
    }
    if (strpos($desc, "TRANSFER") !== false) {
        $kategori = "pulsa transfer";
    }

    /* ---------------- 2. DETEKSI OPERATOR ---------------- */

    $isPromo = false;
    
    if (strpos($operator, "TELKOMSEL") !== false) {
        if (strpos($operator, "BY.U") !== false || strpos($desc, "BY.U") !== false) {
            $brand = "Telkomsel by.U";
            $opKey = "TELKOMSEL BY.U";
        } elseif (strpos($operator, "PROMO") !== false || strpos($desc, "PROMO") !== false || strpos($desc, "HOT") !== false) {
            $brand = "Telkomsel Promo";
            $opKey = "TELKOMSEL PROMO";
            $isPromo = true;
        } else {
            $brand = "Telkomsel";
            $opKey = "TELKOMSEL";
        }
    } elseif (strpos($operator, "XL") !== false) {
        $brand = "XL";
        $opKey = "XL";
    } elseif (strpos($operator, "INDOSAT") !== false || strpos($operator, "ISAT") !== false) {
        $brand = "Indosat";
        $opKey = "INDOSAT";
    } elseif (strpos($operator, "AXIS") !== false) {
        $brand = "Axis";
        $opKey = "AXIS";
    } elseif (strpos($operator, "TRI") !== false || strpos($operator, "THREE") !== false) {
        $brand = "TRI";
        $opKey = "TRI";
    } else {
        continue;
    }

    /* ---------------- 3. LOGIKA MARGIN HARGA ---------------- */

    $price = (int)$item['price'];
    $code  = $item['code'];

    // Ekstrak angka saja dari kode produk (contoh: S100 -> 100, AX5 -> 5)
    preg_match('/\d+/', $code, $match);
    $nominalPola = $match[0] ?? 0;

    $add = $markupDefault;
    if (isset($markup[$kategori][$opKey])) {
        $add = $markup[$kategori][$opKey];
    }
    if (isset($markup["nominal_bonus"][$nominalPola])) {
        $add += $markup["nominal_bonus"][$nominalPola];
    }

    $finalPrice = $price + $add;

    /* ---------------- 4. UBAH NAMA PRODUK TOTAL (PILIHAN 2) ---------------- */
    
    // Default teks bantuan
    $namaFinal = ucwords(strtolower($item['description'])); 
    $deskripsiTampil = "Layanan otomatis aktif instan";

    if ($kategori === "pulsa" || $kategori === "pulsa transfer") {
        // Konversi kode nominal (jika pola 5/10/50, kalikan 1000)
        $angkaNominal = (int)$nominalPola;
        if ($angkaNominal < 1000) {
            $angkaNominal = $angkaNominal * 1000;
        }
        
        $namaFinal = "Pulsa " . number_format($angkaNominal, 0, ',', '.');
        $deskripsiTampil = ($kategori === "pulsa transfer") ? "Pulsa transfer (tidak menambah masa aktif)" : "Menambah masa aktif nomor telepon";
    } 
    elseif ($kategori === "data") {
        // Untuk paket data, nama produk dijadikan sub-deskripsinya agar tidak terlalu kaku
        $namaFinal = "Paket Data " . $nominalPola . "GB";
        
        // Jika tidak ada pola angka GB di kodenya, gunakan deskripsi asli yang dirapikan
        if ((int)$nominalPola == 0) {
            $namaFinal = "Paket Internet";
        }
        $deskripsiTampil = ucwords(strtolower($item['description']));
    }
    elseif ($kategori === "masa aktif") {
        $namaFinal = "Masa Aktif " . $nominalPola . " Hari";
        $deskripsiTampil = "Perpanjang masa tenggang kartu Anda";
    }

    // Penentuan pita promo
    $promoStatus = (strpos($desc, "PROMO") !== false || $isPromo) ? true : false;

    $produkData[$kategori][$brand][] = [
        "kode"      => $code,
        "nama"      => $namaFinal,       // Nama bersih (contoh: Pulsa 10.000)
        "harga"     => (int)$finalPrice,
        "deskripsi" => $deskripsiTampil, // Deskripsi rapi bawaan/custom
        "promo"     => $promoStatus
    ];
}

echo json_encode($produkData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
