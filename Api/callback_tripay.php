<?php
header("Content-Type: application/json; charset=UTF-8");

// === 1. PENGATURAN KUNCI RAHASIA ===
$privateKey           = "644e5a7a-aa2f-4c9f-9a9e-73fdf02863c8";
$portalUserid         = "P176707";
$portalKey            = "014e6ff89d2e0f4c5bcd5320ebc4e780";
$secretKey            = "1a7374c591f83382fbb249c593bcc30af669790be92b3121f590d0f28c1ba615";

// === 2. TANGKAP DATA DARI TRIPAY ===
$callbackJson = file_get_contents('php://input');

// Fungsi Fallback Aman untuk mengambil Header jika getallheaders() tidak tersedia
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

$headers = getallheaders();

// Menangani case-insensitive pada penamaan header
$triPaySignature = isset($headers['X-Callback-Signature']) ? $headers['X-Callback-Signature'] : (isset($headers['x-callback-signature']) ? $headers['x-callback-signature'] : '');
$triPayEvent     = isset($headers['X-Callback-Event']) ? $headers['X-Callback-Event'] : (isset($headers['x-callback-event']) ? $headers['x-callback-event'] : '');

// Validasi apakah data yang masuk kosong
if (empty($callbackJson)) {
    echo json_encode(["status" => false, "message" => "No payload"]);
    exit;
}

// Buat signature tandingan untuk memastikan ini benar-benar kiriman dari Tripay
$localSignature = hash_hmac('sha256', $callbackJson, $privateKey);

if (!hash_equals($triPaySignature, $localSignature)) { 
    echo json_encode(["status" => false, "message" => "Signature tidak valid!"]);
    exit;
}

// Mengubah data JSON menjadi array PHP
$data = json_decode($callbackJson, true);

// Pastikan JSON berhasil di-decode dengan benar
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["status" => false, "message" => "Invalid JSON Payload"]);
    exit;
}

// Hanya proses jika status pembayarannya adalah "PAID"
if ($data['status'] === 'PAID') {
    
    $idInvoice  = $data['merchant_ref']; 
    $nomorHp    = $data['customer_phone'];
    
    // Ambil SKU produk dari payload Tripay
    $kodeProduk = isset($data['order_items'][0]['sku']) ? $data['order_items'][0]['sku'] : null; 

    if (empty($kodeProduk)) {
        echo json_encode(["status" => false, "message" => "Gagal memproses, SKU produk tidak ditemukan dalam payload Tripay"]);
        exit;
    }

    // =========================================================================
    // [OPSIONAL] CEK DATABASE KAMU DI SINI UNTUK MENCEGAH DOUBLE TRANSAKSI
    // =========================================================================
    // $checkDb = mysqli_query($conn, "SELECT status FROM transaksi WHERE invoice = '$idInvoice'");
    // ...
    // =========================================================================

    // === 3. EKSEKUSI TEMBAK API PORTAL PULSA (KIRIM PRODUK) ===
    $orderIdCustom = "TRX" . $idInvoice; // ID Transaksi unik dari sistem Anda tanpa karakter spesial panjang

    // Payload Transaksi Portal Pulsa
    $portalPayload = json_encode([
        "inquiry"      => "TRX",          // Kode perintah transaksi
        "code"         => $kodeProduk,    // Kode produk/SKU dari Portal Pulsa
        "phone"        => $nomorHp,       // Nomor HP / ID Tujuan pengisian
        "trxid_custom" => $orderIdCustom  // ID Transaksi kustom Anda untuk pelacakan
    ]);

    // Setup Custom Headers untuk Autentikasi API Portal Pulsa
    $portalHeader = [
        'portal-userid: ' . $portalUserid,
        'portal-key: ' . $portalKey,
        'portal-secret: ' . $secretKey,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($portalPayload)
    ];

    // Jalankan cURL ke Portal Pulsa
    $ch = curl_init("https://portalpulsa.com/api/v1/connect");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $portalPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $portalHeader);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Mencegah gagal handshake SSL pada server hosting tertentu
    
    $portalResponse = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $resData = json_decode($portalResponse, true);

    // === 4. UPDATE STATUS DI DATABASE KAMU ===
    $statusPulsa = 'Pending'; // Default status jika response mengalami kendala jaringan
    
    if (isset($resData['status'])) {
        if ($resData['status'] === "0") {
            // Status "0" berarti Portal Pulsa sukses menerima instruksi dan sedang memproses (Sama dengan Pending/Proses)
            $statusPulsa = 'Proses'; 
        } else {
            // Jika status tidak bernilai "0", maka transaksi gagal dikirim ke Portal Pulsa
            $statusPulsa = 'Gagal';
            error_log("Portal Pulsa Trx Gagal [" . $orderIdCustom . "]: " . ($resData['message'] ?? 'Unknown Error'));
        }
    } else if (!empty($curl_error)) {
        error_log("cURL Error ke Portal Pulsa: " . $curl_error);
    }
    
    // Contoh Query Update internal silakan diaktifkan & disesuaikan jika memakai DB:
    // mysqli_query($conn, "UPDATE transaksi SET status_pulsa = '$statusPulsa' WHERE invoice = '$idInvoice'");

    // Berikan respons balik ke Tripay agar Tripay tahu callback berhasil kita terima
    echo json_encode([
        "success" => true, 
        "message" => "Callback diproses", 
        "portalpulsa_status" => $statusPulsa
    ]);

} else {
    // Jika status invoice di Tripay bukan 'PAID' (misal: EXPIRED / FAILED)
    echo json_encode(["success" => true, "message" => "Invoice diabaikan (Status: " . $data['status'] . ")"]);
}
?>
