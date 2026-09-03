iMersWAStore PRO — HOTFIX Order Detail (LICENSED / NON-BUSINESS)
Base: assets.zip yang dikirim user
Product Code terdeteksi: IMERS-WSP

Perubahan:
- Fix tombol Pesanan Masuk > Detail yang sebelumnya tidak menampilkan modal.
- Penyebab: overlay modal berada di dalam <tr style="display:none">.
- Fix: saat Detail diklik, overlay dipindahkan ke document.body sebelum ditampilkan.

Instalasi:
1. Backup admin.php lama.
2. Upload admin.php dari hotfix ini ke root aplikasi dan overwrite.
3. Jangan ubah file core/LicenseCore.php, core/LicenseGuard.php, aktivasi.php, includes/db_config.php, atau storage/.license.dat.
4. Hard refresh browser lalu test Pesanan Masuk > Detail.

Hotfix ini tidak mengubah database dan tidak mengubah sistem lisensi.
