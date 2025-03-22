# 🕌 Jadwal Sholat Telegram Bot

Bot Telegram untuk melihat jadwal sholat di seluruh Indonesia dengan mudah dan cepat. Dibangun dengan PHP dan menggunakan API jadwal sholat dari Kementerian Agama RI.

## ✨ Fitur

- 📅 Lihat jadwal sholat harian berdasarkan kota
- 🔍 Pencarian kota dengan kata kunci
- ⭐ Simpan kota favorit sebagai default
- ⏰ Informasi waktu sholat selanjutnya
- 📊 Perbandingan jadwal sholat antar kota
- 📱 Antarmuka interaktif dengan tombol menu permanen

## 🛠️ Cara Penggunaan

1. Mulai percakapan dengan bot [@NamaBotAnda](https://t.me/NamaBotAnda)
2. Gunakan perintah `/start` untuk memulai
3. Lihat jadwal dengan mengetik `/jadwal namaKota` (misalnya: `/jadwal jakarta`)
4. Simpan kota default dengan `/setkota namaKota`
5. Lihat waktu sholat selanjutnya dengan `/waktu`

## 🧩 Perintah Tersedia

- `/jadwal <nama_kota>` - Melihat jadwal sholat di kota tertentu
- `/carikota <kata_kunci>` - Mencari kota yang tersedia
- `/setkota <nama_kota>` - Menyimpan kota default
- `/bandingkan <kota1> <kota2>` - Membandingkan jadwal 2 kota
- `/info` - Menampilkan info bot dan daftar perintah
- `/waktu` - Melihat waktu sholat selanjutnya
- `/help` - Bantuan penggunaan bot
- `/ping` - Mengecek status bot

## 🔧 Instalasi untuk Developer

1. Clone repository ini
```bash
git clone https://github.com/classyid/JadwalSholatBot.git
```

2. Konfigurasi database
```sql
CREATE TABLE user_preferences_telegram (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(50) NOT NULL UNIQUE,
    default_city VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
```

3. Edit file `webhook.php` dan ganti `YOUR_TELEGRAM_BOT_TOKEN` dengan token bot Telegram Anda

4. Upload ke server Anda dan set webhook
```
https://api.telegram.org/botYOUR_TOKEN/setWebhook?url=https://your-domain.com/path/webhook.php
```

## 💡 Kontribusi

Kontribusi selalu diterima! Silakan buat pull request atau ajukan isu jika Anda memiliki ide atau menemukan bug.

## 📝 Lisensi

Didistribusikan di bawah Lisensi MIT. Lihat `LICENSE` untuk informasi lebih lanjut.

## 🙏 Penghargaan
- Dibangun dengan cinta untuk memudahkan ibadah
