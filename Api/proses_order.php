<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight request untuk CORS browser
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Metode request salah, harus POST."]);
    exit;
}

$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);

if (empty($data['nomor_hp']) || empty($data['kode_produk']) || empty($data['metode_pembayaran'])) {
    http_response_code(400);
    echo json_encode(["status" => "failed", "message" => "Data input tidak lengkap."]);
    exit;
}

// Sanitasi data masukan dari frontend
$nomorHp    = filter_var($data['nomor_hp'], FILTER_SANITIZE_NUMBER_INT);
$kodeProduk = htmlspecialchars($data['kode_produk']);
$payment    = strtoupper(htmlspecialchars($data['metode_pembayaran']));

// === KONFIGURASI PORTAL PULSA ===
$portalUserid = "P176707";
$portalKey    = "014e6ff89d2e0f4c5bcd5320ebc4e780";
$secretKey    = "1a7374c591f83382fbb249c593bcc30af669790be92b3121f590d0f28c1ba615";

// === 1. CEK HARGA ASLI KE PORTAL PULSA (Mencegah Kecurangan Frontend) ===
$portalPayload = json_encode([
    "inquiry" => "HARGA", 
    "code"    => $kodeProduk // Cek spesifik kode produk yang dipilih user
]);

$portalHeader = [
    'portal-userid: ' . $portalUserid,
    'portal-key: ' . $portalKey,
    'portal-secret: ' . $secretKey,
    'Content-Type: application/json'
];

$chPortal = curl_init("https://portalpulsa.com/api/v1/connect");
curl_setopt($chPortal, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chPortal, CURLOPT_POST, true);
curl_setopt($chPortal, CURLOPT_POSTFIELDS, $portalPayload);
curl_setopt($chPortal, CURLOPT_HTTPHEADER, $portalHeader);
curl_setopt($chPortal, CURLOPT_TIMEOUT, 10);
curl_setopt($chPortal, CURLOPT_SSL_VERIFYPEER, false);

$portalRes = curl_exec($chPortal);
curl_close($chPortal);

$portalData = json_decode($portalRes, true);

// Pastikan produk ditemukan dan aktif di Portal Pulsa
if (!isset($portalData['status']) || $portalData['status'] !== "0" || empty($portalData['message'])) {
    http_response_code(400);
    echo json_encode(["status" => "failed", "message" => "Produk sedang tidak tersedia di provider."]);
    exit;
}

// Ambil harga modal dari item pertama yang COCOK dengan kode produk
$hargaModal = null;
$namaProduk = "Top Up Produk " . $kodeProduk;

foreach ($portalData['message'] as $item) {
    if ($item['code'] == $kodeProduk && $item['status'] === "1") {
        $hargaModal = (int)$item['price'];
        $namaProduk = $item['description'];
        break;
    }
}

if ($hargaModal === null) {
    http_response_code(400);
    echo json_encode(["status" => "failed", "message" => "Kode produk salah atau sedang gangguan."]);
    exit;
}

// Hitung total harga aman: Harga Modal + Profit Rp 1.500 (Harus sama dengan rumus di pricelist Anda)
$totalHarga = $hargaModal + 1500;

// === 2. KONFIGURASI TRIPAY PAYMENT GATEWAY ===
$idInvoice    = "KP" . time(); // Nomor invoice unik berbasis timestamp
$apiKey       = "cashify_83be088b15e4cf44a7416149936a47461c5d74778098ca113ad4247fb1601d8e";
$privateKey   = "cashify_ba2be2a043a8b57ee6be1c31d92ad8a67d6bb1c889f1033b08109051070f06e285cdd32f96c6675998d53ae01158e8995815bed317eee48a3d78ce6bfe5e971c";
$merchantCode = "online";

// Membuat Signature Keamanan Tripay
$signature = hash_hmac('sha256', $merchantCode . $idInvoice . $totalHarga, $privateKey);

$tripayData = [
    'method'         => $payment,
    'merchant_ref'   => $idInvoice,
    'amount'         => $totalHarga,
    'customer_name'  => 'Pelanggan KiosPulsa',
    'customer_email' => 'pelanggan@kiospulsa.com',
    'customer_phone' => $nomorHp,
    'order_items'    => [
        [
            'sku'      => $kodeProduk,
            'name'     => $namaProduk,
            'price'    => $totalHarga,
            'quantity' => 1
        ]
    ],
    'expired_time'   => (time() + (24 * 60 * 60)), 
    'signature'      => $signature
];

// Tembak cURL ke Tripay Sandbox/Production
$urlEndpoint = "https://tripay.co.id/api-sandbox/transaction/create"; 

$chTripay = curl_init($urlEndpoint);
curl_setopt($chTripay, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chTripay, CURLOPT_POST, true);
curl_setopt($chTripay, CURLOPT_POSTFIELDS, json_encode($tripayData)); 
curl_setopt($chTripay, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($chTripay, CURLOPT_TIMEOUT, 15);

$responseTripay = curl_exec($chTripay);
$curlError = curl_error($chTripay);
curl_close($chTripay);

if ($responseTripay === false) {
    http_response_code(500);
    echo json_encode(["status" => "failed", "message" => "Gagal terhubung ke Tripay: " . $curlError]);
    exit;
}

$result = json_decode($responseTripay, true);

// Memeriksa status keberhasilan respons API Tripay
if (isset($result['success']) && $result['success'] === true) {
    $checkoutUrl = $result['data']['checkout_url'];

    // =========================================================================
    // TEMPAT QUERY DATABASE (Sangat disarankan untuk mencatat transaksi backend)
    // =========================================================================
    // mysqli_query($conn, "INSERT INTO transaksi (invoice, no_hp, sku, total, status) VALUES ('$idInvoice', '$nomorHp', '$kodeProduk', $totalHarga, 'Belum Bayar')");

    echo json_encode([
        "status" => "success",
        "message" => "Nota pembayaran berhasil diterbitkan.",
        "redirect_url" => $checkoutUrl 
    ]);
} else {
    $errMessage = isset($result['message']) ? $result['message'] : "Terjadi kesalahan pada sistem kasir gateway.";
    echo json_encode([
        "status" => "failed",
        "message" => $errMessage
    ]);
}
?>
