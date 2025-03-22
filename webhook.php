<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', 'error.log');

// Atur zona waktu ke Asia/Jakarta (GMT+7)
date_default_timezone_set('Asia/Jakarta');

// Konfigurasi Telegram Bot
$botToken = '<TOKEN>'; // Ganti dengan token bot Telegram Anda

header('content-type: application/json; charset=utf-8');
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) die('this url is for telegram webhook.');

// Log data yang masuk
file_put_contents('telegram.txt', '[' . date('Y-m-d H:i:s') . "]\n" . json_encode($update) . "\n\n", FILE_APPEND);

// Proses data dari Telegram
$message = isset($update['message']['text']) ? strtolower($update['message']['text']) : '';
$chatId = isset($update['message']['chat']['id']) ? $update['message']['chat']['id'] : '';
$firstName = isset($update['message']['from']['first_name']) ? $update['message']['from']['first_name'] : 'Pengguna';

// Utility function untuk mengirim pesan ke Telegram
function sendTelegramMessage($chatId, $text, $replyMarkup = null) {
    global $botToken;
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup !== null) {
        $data['reply_markup'] = $replyMarkup;
    }
    
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    return $result;
}

// Konfigurasi database
$dbConfig = [
    'host' => 'localhost',
    'username' => '<username>',
    'password' => '<password>',
    'database' => '<database>'
];

// Koneksi ke database
function connectDB($config) {
    $conn = new mysqli($config['host'], $config['username'], $config['password'], $config['database']);
    if ($conn->connect_error) {
        error_log("Koneksi database gagal: " . $conn->connect_error);
        return null;
    }
    return $conn;
}

// Fungsi untuk menyimpan kota default pengguna
function saveUserCity($chatId, $city) {
    global $dbConfig;
    $conn = connectDB($dbConfig);
    if (!$conn) return false;
    
    // Ubah menjadi user_preferences_telegram untuk membedakan dengan data WhatsApp
    $stmt = $conn->prepare("SELECT id FROM user_preferences_telegram WHERE chat_id = ?");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update data yang sudah ada
        $stmt = $conn->prepare("UPDATE user_preferences_telegram SET default_city = ?, updated_at = NOW() WHERE chat_id = ?");
        $stmt->bind_param("ss", $city, $chatId);
    } else {
        // Insert data baru
        $stmt = $conn->prepare("INSERT INTO user_preferences_telegram (chat_id, default_city, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->bind_param("ss", $chatId, $city);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

// Fungsi untuk mendapatkan kota default pengguna
function getUserCity($chatId) {
    global $dbConfig;
    $conn = connectDB($dbConfig);
    if (!$conn) return null;
    
    $stmt = $conn->prepare("SELECT default_city FROM user_preferences_telegram WHERE chat_id = ?");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $city = $row['default_city'];
        $stmt->close();
        $conn->close();
        return $city;
    }
    
    $stmt->close();
    $conn->close();
    return null;
}

// Fungsi untuk mengambil data jadwal sholat
function getJadwalSholat($kota) {
    $url = "https://script.google.com/macros/s/AKfycbx8CtuEFQrYxM5sF2pZYvjrcIQa4Mj25lO6BUVqFHrhURw05bg06dBtpeYtvax5NIi1/exec?kota=" . urlencode($kota);
    $response = file_get_contents($url);
    return json_decode($response, true);
}

// Fungsi untuk mendapatkan daftar kota
function getDaftarKota() {
    $url = "https://script.google.com/macros/s/AKfycbx8CtuEFQrYxM5sF2pZYvjrcIQa4Mj25lO6BUVqFHrhURw05bg06dBtpeYtvax5NIi1/exec?action=daftar-kota";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

// Fungsi untuk validasi kota
function validateCity($city) {
    $daftarKota = getDaftarKota();
    if ($daftarKota['status'] === 'success') {
        foreach ($daftarKota['data'] as $kota) {
            if (strtolower($kota['name']) === strtolower($city)) {
                return $kota['name'];
            }
        }
    }
    return false;
}

// Fungsi untuk membuat inline keyboard button (tombol dibawah pesan)
function createInlineKeyboard($buttons) {
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    
    foreach ($buttons as $button) {
        if (isset($button['url'])) {
            $row[] = [
                'text' => $button['text'],
                'url' => $button['url']
            ];
        } else {
            $row[] = [
                'text' => $button['text'],
                'callback_data' => $button['callback_data']
            ];
        }
        
        // Maksimum 2 tombol per baris untuk lebih rapi
        if (count($row) == 2) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    
    // Tambahkan sisa tombol jika ada
    if (!empty($row)) {
        $keyboard['inline_keyboard'][] = $row;
    }
    
    return $keyboard;
}

// Fungsi untuk membuat reply keyboard (menu custom permanen)
function createReplyKeyboard() {
    $keyboard = [
        'keyboard' => [
            [
                ['text' => 'Mulai bot'],
                ['text' => 'Bantuan']
            ],
            [
                ['text' => 'Cari kota'],
                ['text' => 'Lihat jadwal sholat']
            ],
            [
                ['text' => 'Informasi bot'],
                ['text' => 'Waktu sholat selanjutnya']
            ]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'persistent' => true
    ];
    
    return $keyboard;
}

// Inisialisasi respon
$responseText = '';
$replyMarkup = null;

// Handle text button dari menu custom
if ($message === 'mulai bot') {
    $message = '/start';
} elseif ($message === 'bantuan') {
    $message = '/help';
} elseif ($message === 'cari kota') {
    $message = '/cari';
} elseif ($message === 'lihat jadwal sholat') {
    $message = '/jadwal';
} elseif ($message === 'informasi bot') {
    $message = '/info';
} elseif ($message === 'waktu sholat selanjutnya') {
    $message = '/waktu';
}

// Command /start
if ($message === '/start') {
    $responseText = "🌙 <b>Assalamualaikum {$firstName}!</b>\n\n";
    $responseText .= "Selamat datang di Bot Jadwal Sholat. Bot ini akan membantu Anda mengetahui jadwal sholat di seluruh Indonesia.\n\n";
    $responseText .= "Ketik /info untuk melihat daftar perintah yang tersedia.";
    
    $buttons = [
        ['text' => '📋 Lihat Daftar Perintah', 'callback_data' => '/info'],
        ['text' => '🕌 Jadwal Hari Ini', 'callback_data' => '/jadwal']
    ];
    
    $inlineKeyboard = createInlineKeyboard($buttons);
    $replyKeyboard = createReplyKeyboard();
    
    // Gabungkan kedua jenis keyboard
    $replyMarkup = $inlineKeyboard;
    
    // Kirim pesan selamat datang dengan reply keyboard terpisah
    sendTelegramMessage($chatId, $responseText, $replyMarkup);
    
    // Kirim menu utama permanen
    $menuText = "Silakan gunakan menu di bawah ini untuk navigasi cepat:";
    sendTelegramMessage($chatId, $menuText, $replyKeyboard);
    
    // Prevent duplicate messages
    $responseText = '';
    $replyMarkup = null;
}

// Command /help
if ($message === '/help' || $message === '/petunjuk') {
    $responseText = "<b>📖 Bantuan Penggunaan Bot</b>\n\n";
    $responseText .= "Bot ini akan membantu Anda untuk melihat jadwal sholat di seluruh Indonesia.\n\n";
    $responseText .= "<b>Cara Penggunaan:</b>\n";
    $responseText .= "1. Ketik /jadwal diikuti nama kota untuk melihat jadwal sholat.\n";
    $responseText .= "   Contoh: /jadwal surabaya\n\n";
    $responseText .= "2. Untuk mencari kota yang tersedia, gunakan /carikota\n";
    $responseText .= "   Contoh: /carikota kediri\n\n";
    $responseText .= "3. Untuk menyimpan kota default, gunakan /setkota\n";
    $responseText .= "   Contoh: /setkota jakarta\n\n";
    $responseText .= "4. Untuk melihat waktu sholat berikutnya, ketik /waktu\n\n";
    $responseText .= "5. Untuk informasi lengkap bot, ketik /info\n\n";
    $responseText .= "Jika Anda memiliki pertanyaan lain, silakan hubungi admin melalui /kontak";
    
    $buttons = [
        ['text' => '🏠 Menu Utama', 'callback_data' => '/info'],
        ['text' => '🕌 Jadwal Hari Ini', 'callback_data' => '/jadwal']
    ];
    
    $replyMarkup = createInlineKeyboard($buttons);
}

// Command /jadwal <namaKota>
if (strpos($message, '/jadwal') === 0) {
    $messageArray = explode(' ', $message, 2);
    
    // Jika tidak ada parameter kota
    if (count($messageArray) < 2) {
        // Cek apakah pengguna punya kota default
        $defaultCity = getUserCity($chatId);
        
        if ($defaultCity) {
            $jadwal = getJadwalSholat($defaultCity);
            
            if ($jadwal['status'] === 'success') {
                $data = $jadwal['data'];
                $responseText = "🕌 <b>Jadwal Sholat {$data['kota']}</b>\n";
                $responseText .= "📅 {$data['tanggal']}\n";
                $responseText .= "📱 Kota default Anda\n\n";
                $responseText .= "<b>⏰ Jadwal:</b>\n";
                $responseText .= "• Imsyak: {$data['jadwal']['imsyak']} WIB\n";
                $responseText .= "• Subuh: {$data['jadwal']['shubuh']} WIB\n";
                $responseText .= "• Terbit: {$data['jadwal']['terbit']} WIB\n";
                $responseText .= "• Dhuha: {$data['jadwal']['dhuha']} WIB\n";
                $responseText .= "• Dzuhur: {$data['jadwal']['dzuhur']} WIB\n";
                $responseText .= "• Ashar: {$data['jadwal']['ashr']} WIB\n";
                $responseText .= "• Maghrib: {$data['jadwal']['magrib']} WIB\n";
                $responseText .= "• Isya: {$data['jadwal']['isya']} WIB\n\n";
                $responseText .= "<i>Semoga ibadah Anda lancar!</i>";
                
                // Tambahkan tombol untuk aksi cepat
                $buttons = [
                    ['text' => '🏠 Menu Utama', 'callback_data' => '/info'],
                    ['text' => '⚙️ Ubah Kota', 'callback_data' => '/ubahkota']
                ];
                
                $replyMarkup = createInlineKeyboard($buttons);
            } else {
                $responseText = "❌ <b>Terjadi kesalahan</b>\n";
                $responseText .= "Gagal mendapatkan jadwal sholat untuk kota default Anda.\n";
                $responseText .= "Silahkan coba atur ulang kota default dengan perintah:\n";
                $responseText .= "/setkota &lt;nama_kota&gt;";
            }
        } else {
            $responseText = "<b>⏰ Jadwal Sholat</b>\n";
            $responseText .= "Silahkan masukkan nama kota:\n";
            $responseText .= "Contoh: /jadwal kediri\n\n";
            $responseText .= "Untuk menyimpan kota default:\n";
            $responseText .= "/setkota &lt;nama_kota&gt;\n\n";
            $responseText .= "Untuk melihat daftar kota yang tersedia:\n";
            $responseText .= "/carikota &lt;nama_kota&gt;";
        }
    } else {
        $kota = trim($messageArray[1]);
        $jadwal = getJadwalSholat($kota);
        
        if ($jadwal['status'] === 'success') {
            $data = $jadwal['data'];
            $responseText = "🕌 <b>Jadwal Sholat {$data['kota']}</b>\n";
            $responseText .= "📅 {$data['tanggal']}\n\n";
            $responseText .= "<b>⏰ Jadwal:</b>\n";
            $responseText .= "• Imsyak: {$data['jadwal']['imsyak']} WIB\n";
            $responseText .= "• Subuh: {$data['jadwal']['shubuh']} WIB\n";
            $responseText .= "• Terbit: {$data['jadwal']['terbit']} WIB\n";
            $responseText .= "• Dhuha: {$data['jadwal']['dhuha']} WIB\n";
            $responseText .= "• Dzuhur: {$data['jadwal']['dzuhur']} WIB\n";
            $responseText .= "• Ashar: {$data['jadwal']['ashr']} WIB\n";
            $responseText .= "• Maghrib: {$data['jadwal']['magrib']} WIB\n";
            $responseText .= "• Isya: {$data['jadwal']['isya']} WIB\n\n";
            $responseText .= "<i>Semoga ibadah Anda lancar!</i>";
            
            // Tambahkan tombol untuk aksi cepat
            $buttons = [
                ['text' => '🏠 Menu Utama', 'callback_data' => '/info'],
                ['text' => '⭐ Jadikan Default', 'callback_data' => "/setkota {$data['kota']}"]
            ];
            
            $replyMarkup = createInlineKeyboard($buttons);
        } else {
            $responseText = "❌ <b>Kota tidak ditemukan</b>\n";
            $responseText .= "Kota \"{$kota}\" tidak tersedia dalam database.\n\n";
            $responseText .= "Silahkan cari kota dengan perintah:\n";
            $responseText .= "/carikota &lt;sebagian_nama_kota&gt;\n\n";
            $responseText .= "Contoh: /carikota kediri";
        }
    }
}

// Command /cari yang lebih mudah untuk shortcut menu
if ($message === '/cari') {
    $responseText = "<b>🔍 Cari Kota</b>\n";
    $responseText .= "Silahkan masukkan kata kunci nama kota:\n";
    $responseText .= "Contoh: /carikota kediri\n\n";
    $responseText .= "Atau ketik langsung, contoh:\n";
    $responseText .= "/carikota surabaya";
    
    $buttons = [
        ['text' => '🔍 Cari Surabaya', 'callback_data' => '/carikota surabaya'],
        ['text' => '🔍 Cari Jakarta', 'callback_data' => '/carikota jakarta'],
        ['text' => '🔍 Cari Bandung', 'callback_data' => '/carikota bandung'],
        ['text' => '🏠 Menu Utama', 'callback_data' => '/info']
    ];
    
    $replyMarkup = createInlineKeyboard($buttons);
}

// Command /setkota <namaKota>
if (strpos($message, '/setkota') === 0) {
    $messageArray = explode(' ', $message, 2);
    
    if (count($messageArray) < 2) {
        $responseText = "<b>⚙️ Atur Kota Default</b>\n";
        $responseText .= "Silahkan masukkan nama kota yang ingin dijadikan default:\n";
        $responseText .= "Contoh: /setkota kediri";
    } else {
        $kota = trim($messageArray[1]);
        
        // Validasi kota
        $validCity = validateCity($kota);
        
        if ($validCity) {
            // Simpan ke database
            if (saveUserCity($chatId, $validCity)) {
                $responseText = "<b>✅ Kota Default Berhasil Disimpan</b>\n";
                $responseText .= "Kota default Anda sekarang adalah: <b>" . ucfirst($validCity) . "</b>\n\n";
                $responseText .= "Anda sekarang bisa cek jadwal dengan mengetik:\n";
                $responseText .= "/jadwal (tanpa parameter kota)";
                
                $buttons = [
                    ['text' => '🕌 Lihat Jadwal Sholat', 'callback_data' => '/jadwal'],
                    ['text' => '🏠 Menu Utama', 'callback_data' => '/info']
                ];
                
                $replyMarkup = createInlineKeyboard($buttons);
            } else {
                $responseText = "<b>❌ Gagal Menyimpan Kota Default</b>\n";
                $responseText .= "Terjadi kesalahan saat menyimpan data. Silahkan coba lagi nanti.";
            }
        } else {
            $responseText = "<b>❌ Kota Tidak Valid</b>\n";
            $responseText .= "Kota \"{$kota}\" tidak ditemukan dalam database.\n\n";
            $responseText .= "Silahkan cari kota yang valid dengan perintah:\n";
            $responseText .= "/carikota &lt;sebagian_nama_kota&gt;";
        }
    }
}

// Command /carikota <keyword>
if (strpos($message, '/carikota') === 0) {
    $messageArray = explode(' ', $message, 2);
    if (count($messageArray) < 2) {
        $responseText = "<b>🔍 Cari Kota</b>\n";
        $responseText .= "Silahkan masukkan kata kunci nama kota:\n";
        $responseText .= "Contoh: /carikota kediri";
    } else {
        $keyword = strtolower(trim($messageArray[1]));
        $daftarKota = getDaftarKota();
        
        if ($daftarKota['status'] === 'success') {
            $kotaList = $daftarKota['data'];
            $hasilPencarian = [];
            
            foreach ($kotaList as $kota) {
                if (strpos(strtolower($kota['name']), $keyword) !== false) {
                    $hasilPencarian[] = $kota['name'];
                }
                
                // Batasi hasil pencarian ke 20 kota
                if (count($hasilPencarian) >= 20) {
                    break;
                }
            }
            
            if (count($hasilPencarian) > 0) {
                $responseText = "<b>🔍 Hasil Pencarian Kota: \"{$keyword}\"</b>\n";
                $responseText .= "Ditemukan " . count($hasilPencarian) . " kota:\n\n";
                
                // Hanya tampilkan 5 hasil teratas di pesan
                for ($i = 0; $i < min(5, count($hasilPencarian)); $i++) {
                    $responseText .= ($i + 1) . ". " . ucfirst($hasilPencarian[$i]) . "\n";
                }
                
                if (count($hasilPencarian) > 5) {
                    $responseText .= "...\n";
                }
                
                $responseText .= "\nUntuk melihat jadwal, ketik:\n";
                $responseText .= "/jadwal &lt;nama_kota&gt;";
                
                // Buat tombol untuk semua hasil pencarian (max 8 tombol)
                $buttons = [];
                for ($i = 0; $i < min(8, count($hasilPencarian)); $i++) {
                    $buttons[] = ['text' => "🕌 " . ucfirst($hasilPencarian[$i]), 'callback_data' => "/jadwal {$hasilPencarian[$i]}"];
                }
                
                // Tambahkan tombol menu utama
                $buttons[] = ['text' => '🏠 Menu Utama', 'callback_data' => '/info'];
                
                $replyMarkup = createInlineKeyboard($buttons);
            } else {
                $responseText = "<b>🔍 Hasil Pencarian Kota: \"{$keyword}\"</b>\n";
                $responseText .= "Maaf, tidak ditemukan kota dengan kata kunci tersebut.\n\n";
                $responseText .= "Silahkan coba dengan kata kunci lain.";
            }
        } else {
            $responseText = "❌ <b>Terjadi kesalahan</b>\n";
            $responseText .= "Gagal mendapatkan daftar kota.\n";
            $responseText .= "Silahkan coba lagi nanti.";
        }
    }
}

// Command /info
if ($message === '/info') {
    $responseText = "<b>ℹ️ Jadwal Sholat Bot</b>\n";
    $responseText .= "Bot untuk mengecek jadwal sholat di seluruh Indonesia\n\n";
    $responseText .= "<b>📋 Daftar Perintah:</b>\n";
    $responseText .= "• /jadwal &lt;nama_kota&gt;\n";
    $responseText .= "  Untuk melihat jadwal sholat di kota tertentu\n\n";
    $responseText .= "• /carikota &lt;kata_kunci&gt;\n";
    $responseText .= "  Untuk mencari kota yang tersedia\n\n";
    $responseText .= "• /setkota &lt;nama_kota&gt;\n";
    $responseText .= "  Untuk menyimpan kota default\n\n";
    $responseText .= "• /bandingkan &lt;kota1&gt; &lt;kota2&gt;\n";
    $responseText .= "  Untuk membandingkan jadwal 2 kota\n\n";
    $responseText .= "• /info\n";
    $responseText .= "  Menampilkan info bot dan daftar perintah\n\n";
    $responseText .= "• /waktu\n";
    $responseText .= "  Melihat waktu sholat yang akan datang\n\n";
    $responseText .= "<i>Dibuat dengan ❤️ untuk memudahkan ibadah</i>";
    
    $buttons = [
        ['text' => '🕌 Jadwal Hari Ini', 'callback_data' => '/jadwal'],
        ['text' => '⏰ Waktu Sholat Selanjutnya', 'callback_data' => '/waktu']
    ];
    
    $replyMarkup = createInlineKeyboard($buttons);
}

// Command /waktu - Menampilkan waktu sholat selanjutnya
if ($message === '/waktu') {
    // Gunakan kota default jika ada
    $kota = getUserCity($chatId) ?: "jakarta";
    $jadwal = getJadwalSholat($kota);
    
    if ($jadwal['status'] === 'success') {
        $data = $jadwal['data'];
        $jadwalHariIni = $data['jadwal'];
        
        // Konversi ke timestamp untuk perbandingan
        $waktuSekarang = time();
        $waktuSholat = [
            'Subuh' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['shubuh']),
            'Dzuhur' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['dzuhur']),
            'Ashar' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['ashr']),
            'Maghrib' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['magrib']),
            'Isya' => strtotime(date('Y-m-d') . ' ' . $jadwalHariIni['isya'])
        ];
        
        // Temukan waktu sholat selanjutnya
        $sholatSelanjutnya = null;
        $waktuSelanjutnya = null;
        
        foreach ($waktuSholat as $namaSholat => $waktu) {
            if ($waktu > $waktuSekarang) {
                $sholatSelanjutnya = $namaSholat;
                $waktuSelanjutnya = $waktu;
                break;
            }
        }
        
        // Jika semua waktu sholat hari ini sudah lewat, tampilkan Subuh besok
        if ($sholatSelanjutnya === null) {
            $sholatSelanjutnya = 'Subuh (besok)';
            $waktuSelanjutnya = strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . $jadwalHariIni['shubuh']);
        }
        
        // Hitung selisih waktu
        $selisih = $waktuSelanjutnya - $waktuSekarang;
        $jam = floor($selisih / 3600);
        $menit = floor(($selisih % 3600) / 60);
        
        $responseText = "<b>⏰ Waktu Sholat Selanjutnya</b>\n";
        $responseText .= "📍 Kota: {$data['kota']}\n";
        $responseText .= "📅 {$data['tanggal']}\n\n";
        $responseText .= "<b>🕌 {$sholatSelanjutnya}: " . date('H:i', $waktuSelanjutnya) . " WIB</b>\n";
        $responseText .= "⏱️ {$jam} jam {$menit} menit lagi\n\n";
        $responseText .= "Untuk melihat jadwal lengkap:\n";
        $responseText .= "/jadwal " . strtolower($data['kota']);
        
        $buttons = [
            ['text' => '🏠 Menu Utama', 'callback_data' => '/info'],
            ['text' => '📋 Jadwal Lengkap', 'callback_data' => "/jadwal {$data['kota']}"]
        ];
        
        $replyMarkup = createInlineKeyboard($buttons);
    } else {
        $responseText = "❌ <b>Terjadi kesalahan</b>\n";
        $responseText .= "Gagal mendapatkan jadwal sholat.\n";
        $responseText .= "Silahkan coba lagi nanti.";
    }
}

// Command /bandingkan <kota1> <kota2>
if (strpos($message, '/bandingkan') === 0) {
    $parts = explode(' ', $message, 3);
    
    if (count($parts) < 3) {
        $responseText = "<b>📊 Perbandingan Jadwal Sholat</b>\n";
        $responseText .= "Gunakan format: /bandingkan &lt;kota1&gt; &lt;kota2&gt;\n\n";
        $responseText .= "Contoh: /bandingkan jakarta surabaya";
    } else {
        $kota1 = trim($parts[1]);
        $kota2 = trim($parts[2]);
        
        $jadwal1 = getJadwalSholat($kota1);
        $jadwal2 = getJadwalSholat($kota2);
        
        if ($jadwal1['status'] === 'success' && $jadwal2['status'] === 'success') {
            $data1 = $jadwal1['data'];
            $data2 = $jadwal2['data'];
            
            $responseText = "<b>📊 Perbandingan Jadwal Sholat</b>\n";
            $responseText .= "📅 {$data1['tanggal']}\n\n";
            $responseText .= "<b>⏰ " . ucfirst($data1['kota']) . " vs " . ucfirst($data2['kota']) . ":</b>\n\n";
            
            // Bandingkan setiap waktu sholat
            $waktuSholat = [
                'Imsyak' => ['imsyak', 'imsyak'],
                'Subuh' => ['shubuh', 'shubuh'],
                'Terbit' => ['terbit', 'terbit'],
                'Dhuha' => ['dhuha', 'dhuha'],
                'Dzuhur' => ['dzuhur', 'dzuhur'],
                'Ashar' => ['ashr', 'ashr'],
                'Maghrib' => ['magrib', 'magrib'],
                'Isya' => ['isya', 'isya']
            ];
            
            foreach ($waktuSholat as $nama => $keys) {
                $waktu1 = $data1['jadwal'][$keys[0]];
                $waktu2 = $data2['jadwal'][$keys[1]];
                
                // Hitung selisih waktu dalam menit
                $time1 = strtotime(date('Y-m-d') . ' ' . $waktu1);
                $time2 = strtotime(date('Y-m-d') . ' ' . $waktu2);
                $selisihMenit = abs($time1 - $time2) / 60;
                
                $selisihText = '';
                if ($time1 < $time2) {
                    $selisihText = ' (' . ucfirst($data1['kota']) . ' lebih awal ' . $selisihMenit . ' menit)';
                } elseif ($time1 > $time2) {
                    $selisihText = ' (' . ucfirst($data2['kota']) . ' lebih awal ' . $selisihMenit . ' menit)';
                }
                
                $responseText .= "• {$nama}: {$waktu1} vs {$waktu2}{$selisihText}\n";
            }
            
            $responseText .= "\nInformasi ini berguna bagi musafir yang bepergian antar kota.";
            
            $buttons = [
                ['text' => "🕌 Jadwal " . ucfirst($data1['kota']), 'callback_data' => "/jadwal {$data1['kota']}"],
                ['text' => "🕌 Jadwal " . ucfirst($data2['kota']), 'callback_data' => "/jadwal {$data2['kota']}"],
                ['text' => '🏠 Menu Utama', 'callback_data' => '/info']
            ];
            
            $replyMarkup = createInlineKeyboard($buttons);
        } else {
            $errorMsg = '';
            if ($jadwal1['status'] !== 'success') {
                $errorMsg .= 'Kota "' . $kota1 . '" tidak ditemukan. ';
            }
            if ($jadwal2['status'] !== 'success') {
                $errorMsg .= 'Kota "' . $kota2 . '" tidak ditemukan. ';
            }
            
            $responseText = "<b>❌ Kota Tidak Ditemukan</b>\n";
            $responseText .= $errorMsg . "\n\n";
            $responseText .= "Silahkan cari kota yang valid dengan perintah:\n";
            $responseText .= "/carikota &lt;nama_kota&gt;";
        }
    }
}

// Command /kontak - Menampilkan kontak admin
if ($message === '/kontak') {
    $responseText = "<b>📞 Kontak Admin</b>\n\n";
    $responseText .= "Jika Anda memiliki pertanyaan, saran, atau menemukan bug, silakan hubungi admin melalui:\n\n";
    $responseText .= "Email: admin@example.com\n";
    $responseText .= "Telegram: @admin_username\n";
    $responseText .= "Website: https://example.com";
    
    $buttons = [
        ['text' => '📩 Kirim Email', 'url' => 'mailto:admin@example.com'],
        ['text' => '💬 Chat Admin', 'url' => 'https://t.me/admin_username'],
        ['text' => '🏠 Menu Utama', 'callback_data' => '/info']
    ];
    
    $replyMarkup = createInlineKeyboard($buttons);
}

// Command /ping - Untuk mengecek apakah bot berjalan
if ($message === '/ping') {
    $responseText = "🟢 Pong! Bot aktif dan berjalan dengan baik.\n";
    $responseText .= "Waktu Server: " . date('Y-m-d H:i:s') . " WIB";
}

// Untuk menangani perintah tambahan lainnya
if ($message === '/petunjuk') {
    $message = '/help'; // Redirect ke /help
}

// Keyword umum dan salam
// Keyword umum dan salam
$keywords = [
    'assalamualaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'asalamualaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'assalamu\'alaikum' => '🌙 Wa\'alaikumussalam Warahmatullahi Wabarakatuh',
    'halo' => 'Halo! Ada yang bisa saya bantu?\nKetik /info untuk melihat daftar perintah',
    'hi' => 'Hi! Silahkan ketik /info untuk bantuan',
    'hello' => 'Hello! Silahkan ketik /info untuk bantuan'
];

foreach ($keywords as $key => $value) {
    if ($message === $key || strpos($message, $key) === 0) {
        $responseText = $value;
        
        // Tambahkan tombol info untuk salam
        $buttons = [
            ['text' => '📋 Daftar Perintah', 'callback_data' => '/info'],
            ['text' => '🕌 Jadwal Hari Ini', 'callback_data' => '/jadwal']
        ];
        $replyMarkup = createInlineKeyboard($buttons);
        break;
    }
}

// Pesan default jika perintah tidak dikenali
if (empty($responseText) && !empty($message) && $message[0] === '/') {
    $responseText = "❌ <b>Perintah tidak dikenali</b>\n";
    $responseText .= "Silahkan ketik /info untuk melihat daftar perintah yang tersedia.";
    
    $buttons = [
        ['text' => '📋 Daftar Perintah', 'callback_data' => '/info']
    ];
    $replyMarkup = createInlineKeyboard($buttons);
}

// Jika ada pesan untuk dikirim
if (!empty($responseText)) {
    $result = sendTelegramMessage($chatId, $responseText, $replyMarkup);
    file_put_contents('response.txt', '[' . date('Y-m-d H:i:s') . "]\n" . $result . "\n\n", FILE_APPEND);
}

// Hook untuk menangani callback query
if (isset($update['callback_query'])) {
    $callbackData = $update['callback_query']['data'];
    $callbackChatId = $update['callback_query']['message']['chat']['id'];
    $callbackMessageId = $update['callback_query']['message']['message_id'];
    
    // Acknowledge the callback query
    $url = "https://api.telegram.org/bot{$botToken}/answerCallbackQuery";
    $data = [
        'callback_query_id' => $update['callback_query']['id']
    ];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
    
    // Proses callback data sebagai pesan baru
    // Ini akan menjalankan perintah yang dikirim melalui tombol
    $fakeUpdate = [
        'message' => [
            'text' => $callbackData,
            'chat' => [
                'id' => $callbackChatId
            ],
            'from' => $update['callback_query']['from']
        ]
    ];
    
    // Simpan update baru untuk diproses nanti
    file_put_contents('callback_to_process.json', json_encode($fakeUpdate));
    
    // Opsional: Mengarahkan ke skrip ini lagi untuk memproses callback secara langsung
    // Ini akan membuat tombol merespons secara instan
    $selfUrl = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($fakeUpdate)
        ]
    ];
    $context = stream_context_create($options);
    @file_get_contents($selfUrl, false, $context);
}

// Untuk registrasi command pada BotFather, kirimkan string ini ke /setcommands:
// jadwal - Melihat jadwal sholat di kota tertentu
// carikota - Mencari kota yang tersedia
// setkota - Menyimpan kota default
// bandingkan - Membandingkan jadwal 2 kota
// info - Menampilkan info bot dan daftar perintah
// waktu - Melihat waktu sholat yang akan datang
// help - Bantuan penggunaan bot
// kontak - Menampilkan kontak admin
// ping - Mengecek status bot

echo 'OK';
?>
