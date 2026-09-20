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

/* ================= CURL ================= */

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
    die(json_encode(["error" => curl_error($ch)]));
}
curl_close($ch);

$json = json_decode($result, true);

if (!isset($json['result']) || $json['result'] !== 'success') {
    die(json_encode($json));
}

/* ================= SETTING HARGA ================= */

$markupDefault = 1500;

/* pakai KEY CONTAINS bukan full string */
$markup = [
    "TELKOMSEL" => 0,
    "XL"        => 1800,
    "INDOSAT"   => 1800,
    "AXIS"      => 1700,
    "TRI"       => 1600
];

$bonusNominal = [
    "5"   => 500,
    "10"  => 800,
    "20"  => 1000,
    "50"  => 1500,
    "100" => 2000
];

/* ================= MANUAL PRODUCT ================= */

$produkManual = [
    "data" => [
        "Telkomsel" => [
            [
                "kode" => "DM1",
                "nama" => "DATA 1GB MANUAL",
                "harga" => "10000",
                "deskripsi" => "Produk manual admin"
            ]
        ]
    ],
    "pulsa" => [
        "Telkomsel" => [
            [
                "kode" => "SDF1",
                "nama" => "10.000",
                "harga" => "12.000",
                "deskripsi" => "Produk manual admin"
            ]
        ]
    ]
];

/* ================= INIT ================= */

$produkData = [
    "pulsa" => [],
    "data" => [],
    "nelpon" => [],
    "sms" => [],
    "masa aktif" => [],
    "pulsa transfer" => []
];

/* ================= PARSE API ================= */

foreach ($json['message'] as $item) {

    if (($item['status'] ?? '') !== 'normal') continue;

    $operator = strtoupper($item['operator'] ?? '');
    $sub = strtoupper($item['provider_sub'] ?? '');
    $desc = strtoupper($item['description'] ?? '');

    /* CATEGORY */
    $kategori = "pulsa";

    if (strpos($sub, "INTERNET") !== false || strpos($operator, "DATA") !== false) {
        $kategori = "data";
    }
    if (strpos($sub, "SMS") !== false) $kategori = "sms";
    if (strpos($sub, "TELPON") !== false) $kategori = "nelpon";
    if (strpos($desc, "MASA AKTIF") !== false) $kategori = "masa aktif";
    if (strpos($desc, "TRANSFER") !== false) $kategori = "pulsa transfer";

    /* BRAND */
    $brand = null;

    if (strpos($operator, "TELKOMSEL") !== false) $brand = "Telkomsel";
    elseif (strpos($operator, "XL") !== false) $brand = "XL";
    elseif (strpos($operator, "INDOSAT") !== false) $brand = "Indosat";
    elseif (strpos($operator, "AXIS") !== false) $brand = "Axis";
    elseif (strpos($operator, "TRI") !== false) $brand = "TRI";

    if (!$brand) continue;

    /* HARGA */
    $base = (int)$item['price'];

    preg_match('/\d+/', $item['code'], $m);
    $nominal = $m[0] ?? 0;

    $nominalKey = (string) $nominal;

    $add = $markupDefault;

    foreach ($markup as $k => $v) {
        if (strpos($operator, $k) !== false) {
            $add = $v;
            break;
        }
    }

    foreach ($bonusNominal as $k => $v) {
        if (strpos($nominalKey, $k) !== false) {
            $add += $v;
        }
    }

    $final = $base + $add;

    $produkData[$kategori][$brand][] = [
        "kode" => $item['code'],
        "nama" => $item['description'],
        "harga" => number_format($final, 0, ',', '.'),
        "deskripsi" => "Auto pricing"
    ];
}

/* ================= MERGE MANUAL ================= */

foreach ($produkManual as $kategori => $brands) {
    foreach ($brands as $brand => $items) {

        if (!isset($produkData[$kategori][$brand])) {
            $produkData[$kategori][$brand] = [];
        }

        $produkData[$kategori][$brand] = array_merge(
            $produkData[$kategori][$brand],
            $items
        );
    }
}

echo json_encode($produkData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);