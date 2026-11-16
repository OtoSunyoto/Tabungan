APLIKASI TABUNGAN TARGET (PHP Native + MySQL)
=============================================

CARA PASANG DI XAMPP
1. Jalankan Apache dan MySQL di XAMPP Control Panel.
2. Salin folder "tabungan" ke:  C:\xampp\htdocs\tabungan
3. Buka http://localhost/phpmyadmin
   -> tab "Import" -> pilih file database.sql -> klik "Import" / "Go".
   (Database "tabungan_db" beserta 2 tabel akan dibuat otomatis.)
4. Buka aplikasi di:  http://localhost/tabungan/

PENGATURAN DATABASE
Jika user/password MySQL kamu bukan default XAMPP (root, tanpa password),
ubah di file config.php bagian "PENGATURAN DATABASE".

FITUR
- Login (username & password). Saat pertama dibuka, kamu diminta membuat akun.
- Saldo vs Cash: setiap setoran/penarikan dicatat disimpan di Saldo (rekening/e-wallet)
  atau Cash (tunai). Total per jenis tampil di dashboard dan di tiap target.
- Pindah dana Saldo <-> Cash (mis. tarik tunai ATM) tanpa mengubah total tabungan.
- Grafik: aktivitas 6 bulan terakhir (dashboard) dan perkembangan saldo per target.
- Estimasi tanggal tercapai berdasarkan laju menabung 90 hari terakhir.
- Buat banyak target (mis. Laptop, Motor, Liburan) dengan nominal & tanggal target.
- Catat setoran / penarikan per target.
- Dashboard: progres (bar %), sisa kekurangan, saran nabung per bulan.
- Export ke Excel (.xlsx): semua target, atau per target (tombol di halaman detail).
  Sheet "Ringkasan" (pakai formula Excel) dan sheet "Transaksi".

UPDATE DARI VERSI LAMA
Cukup timpa semua file. Tabel login, kolom Saldo/Cash, dan tabel pemindahan dibuat otomatis oleh
aplikasi. Transaksi lama dianggap "Saldo". (update.sql = cadangan manual.)
Lupa password? Di phpMyAdmin jalankan:  DELETE FROM users;  lalu buka halaman login.

STRUKTUR FILE
- database.sql     : struktur database (import ke phpMyAdmin)
- config.php       : koneksi database + fungsi bantu
- index.php        : dashboard
- target_form.php  : tambah / edit target
- target_detail.php: catat transaksi + riwayat
- export.php       : export Excel
- login.php, logout.php : masuk / keluar
- charts.php       : grafik SVG
- update.sql       : cadangan update database
- header.php, footer.php, style.css : tampilan

CATATAN
- Jika export Excel gagal, aktifkan ekstensi zip: buka php.ini di XAMPP,
  pastikan baris "extension=zip" tidak diawali tanda titik koma (;), lalu
  restart Apache. (Tanpa ekstensi ini, export otomatis jadi file CSV.)
- Data tersimpan di MySQL (tabungan_db). Disarankan backup rutin lewat phpMyAdmin > Ekspor.
- Jika ingin di-online-kan, pakai HTTPS dan password yang kuat.
