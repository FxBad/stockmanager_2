# Guide & SOP Pengguna Role **Field**

Dokumen ini adalah panduan operasional lengkap untuk pengguna dengan peran **Field** pada aplikasi StockManager.

## 1) Tujuan Dokumen

- Menstandarkan cara kerja user Field saat memperbarui data stok di lapangan.
- Menjamin data stok yang masuk konsisten, valid, dan dapat diaudit.
- Menetapkan alur eskalasi saat ditemukan anomali (termasuk item fisik yang belum terdaftar).

## 2) Ruang Lingkup Hak Akses Role Field

### Yang bisa dilakukan

- Login ke aplikasi.
- Melihat dashboard dan daftar item aktif.
- Memperbarui stok lapangan melalui halaman **Perbarui Stok**.
- Memperbarui nilai level untuk item yang memang menggunakan indikator level.

### Yang tidak bisa dilakukan

- Menambah item baru.
- Mengubah master item (nama, kategori, satuan, konversi, mode perhitungan, dll).
- Menghapus item.
- Mengelola user/master data.

> Catatan: perubahan master item hanya dilakukan oleh role **Office/Admin**.

## 3) Istilah Penting

- **Field Stock**: jumlah stok fisik yang diinput user Field.
- **Level**: indikator level (cm) untuk item tertentu yang mengaktifkan mode level.
- **Days Coverage**: estimasi ketahanan stok (hari) hasil perhitungan sistem.
- **Min Days Coverage**: batas minimum hari aman per item.
- **Status Stok**: `out-stock`, `low-stock`, `warning-stock`, `in-stock`.

## 4) Alur Kerja Harian User Field

### A. Persiapan sebelum input

1. Pastikan akun user Field aktif dan dapat login.
2. Pastikan koneksi internet stabil.
3. Siapkan catatan hasil inspeksi stok fisik (item, kuantitas, level bila ada, waktu cek).

### B. Login

1. Buka aplikasi dan login menggunakan akun Field.
2. Jika gagal login:
	- Cek username/password.
	- Ulangi sekali lagi.
	- Jika tetap gagal, hubungi Admin.

### C. Update stok melalui halaman Perbarui Stok

1. Buka menu **Perbarui Stok**.
2. Filter kategori jika diperlukan.
3. Untuk tiap item:
	- Isi/ubah nilai **Stok** sesuai kondisi fisik.
	- Jika item memiliki mode level aktif, isi **Level** bila ada perubahan.
4. Simpan perubahan.
5. Pastikan notifikasi sukses muncul.

### D. Verifikasi hasil

1. Cek nilai status item setelah update.
2. Jika status tidak sesuai ekspektasi, lakukan:
	- Verifikasi ulang input stok/level.
	- Verifikasi item yang dipilih benar.
	- Jika masih tidak sesuai, laporkan ke Office/Admin untuk review parameter item.

## 5) SOP Anomali Data (Wajib)

## 5.1 Item fisik ditemukan tetapi **belum terdaftar** di sistem

Karena role Field tidak punya izin membuat master item, ikuti alur berikut:

1. **Jangan** memaksakan input ke item lain sebagai pengganti.
2. Catat detail minimal:
	- Nama item (sesuai label fisik)
	- Kategori usulan
	- Satuan usulan
	- Estimasi stok saat ditemukan
	- Lokasi/area
	- Waktu temuan
3. Kirim eskalasi ke Office/Admin (kanal resmi tim).
4. Setelah Office/Admin menambahkan item master, baru lakukan update stok item tersebut.
5. Simpan bukti komunikasi eskalasi (untuk audit proses).

Template eskalasi singkat:

```
[ANOMALI ITEM BARU]
Nama item:
Kategori usulan:
Satuan usulan:
Estimasi stok:
Lokasi:
Waktu temuan:
Catatan tambahan:
```

## 5.2 Item ada di sistem tetapi data fisik tidak cocok besar

1. Input nilai sesuai fisik terakhir yang valid.
2. Tambahkan catatan kejadian di kanal operasional.
3. Minta Office/Admin melakukan investigasi histori perubahan item.

## 5.3 Input gagal / error server

1. Simpan screenshot pesan error.
2. Ulangi submit 1 kali setelah refresh.
3. Jika masih gagal, kirim laporan ke Admin berisi:
	- Waktu kejadian
	- Nama item yang diubah
	- Nilai stok/level yang diinput
	- Screenshot error

## 6) Aturan Kualitas Data

- Gunakan angka aktual hasil cek fisik, bukan perkiraan lama.
- Dilarang input angka negatif.
- Untuk item level-enabled, input level harus angka bulat valid.
- Lakukan update segera setelah pengecekan lapangan (hindari penundaan).
- Hindari update ganda oleh dua user untuk item yang sama pada waktu berdekatan tanpa koordinasi.

## 6.1 Kriteria Validasi Inspeksi Fisik (Standar Wajib)

Sebelum data diinput, user Field wajib memastikan seluruh kriteria berikut terpenuhi:

### A. Validasi identitas item

- Item yang dicek harus cocok dengan nama item di sistem.
- Jika tidak ditemukan di sistem, ikuti SOP anomali **5.1** (jangan substitusi ke item lain).
- Lokasi inspeksi item harus sesuai area kerja user/shift.

### B. Validasi kondisi pengukuran

- Pengukuran stok dilakukan pada kondisi stabil (bukan saat transfer aktif yang belum selesai dicatat).
- Pengukuran level dilakukan saat indikator/alat ukur dapat dibaca jelas.
- Jika pembacaan meragukan, lakukan pengukuran ulang minimal 1x sebelum input.

### C. Validasi konsistensi waktu

- Waktu inspeksi dan waktu input tidak boleh berjeda terlalu lama.
- Jika jeda > 2 jam, user wajib konfirmasi ulang kondisi fisik singkat sebelum submit.

### D. Validasi kewajaran angka

- Nilai input tidak boleh negatif.
- Perubahan ekstrem wajib diverifikasi ulang:
  - Stok berubah > 50% dibanding pembacaan terakhir.
  - Level turun/naik drastis tidak sesuai aktivitas operasional.

## 6.2 Parameter Kondisi Barang yang Diizinkan Diinput (Role Field)

Parameter yang boleh diinput user Field di antarmuka update stok hanya:

1. **field_stock**
	- Tipe: bilangan bulat
	- Nilai minimum: 0
	- Nilai negatif: tidak diizinkan
	- Sumber: hasil hitung fisik aktual

2. **level** (hanya jika item `has_level = 1`)
	- Tipe: bilangan bulat
	- Nilai minimum: 0
	- Jika item tidak level-enabled: tidak boleh diisi

Parameter berikut **tidak boleh** diubah oleh user Field:

- Nama barang
- Kategori
- Satuan
- Unit conversion
- Level conversion / custom conversion
- Mode perhitungan (`combined` / `multiplied`)
- Daily consumption
- Min days coverage

## 6.3 Aturan Keputusan Input: Terima / Tahan / Tolak

### Terima input (boleh submit)

- Item ditemukan di sistem.
- Nilai `field_stock` valid (integer, >= 0).
- Nilai `level` valid (integer, >= 0) untuk item level-enabled.
- Tidak ada indikasi salah item atau salah satuan.

### Tahan input (pending verifikasi)

- Ada keraguan pada hasil ukur fisik.
- Perubahan nilai sangat ekstrem dan tidak ada kejadian operasional pendukung.
- Terjadi selisih data antar petugas dalam shift yang sama.

### Tolak input (jangan submit)

- Item fisik belum terdaftar di sistem.
- Nilai negatif atau format tidak valid.
- User mencoba memasukkan level pada item non-level.
- User belum melakukan ukur ulang saat data meragukan.

## 6.4 Bukti Minimum Inspeksi (Opsional tetapi direkomendasikan)

Untuk item kritikal atau saat anomali, simpan bukti pendukung:

- Foto indikator level / area penyimpanan
- Catatan waktu inspeksi
- Nama petugas yang melakukan verifikasi silang

Tujuan bukti: mempercepat investigasi jika terjadi deviasi status stok.

## 7) Interpretasi Status untuk User Field

- **Habis (`out-stock`)**: stok tidak mencukupi (coverage <= 0).
- **Stok Rendah (`low-stock`)**: stok di bawah/sekitar ambang minimum.
- **Peringatan (`warning-stock`)**: stok di atas minimum tetapi belum aman.
- **Tersedia (`in-stock`)**: stok aman.

Fokus user Field: memastikan **input fisik akurat**. Penyesuaian parameter rumus adalah tanggung jawab Office/Admin.

## 8) Checklist Operasional Shift

### Awal shift

- [ ] Login berhasil
- [ ] Daftar area inspeksi tersedia
- [ ] Perangkat siap (baterai, koneksi)

### Saat inspeksi

- [ ] Semua item di area dicek
- [ ] Stok diinput sesuai fisik
- [ ] Level diinput untuk item level-enabled
- [ ] Item tak terdaftar dieskalasi sesuai SOP

### Akhir shift

- [ ] Tidak ada perubahan yang tertinggal
- [ ] Semua anomali sudah dilaporkan
- [ ] Bukti/screenshot error (jika ada) tersimpan

## 9) SLA Operasional yang Disarankan

- Temuan item tidak terdaftar: eskalasi maksimal **15 menit** setelah ditemukan.
- Perbaikan input salah (jika terdeteksi): koreksi maksimal **30 menit** dari waktu temuan.
- Follow-up anomali ke Office/Admin: maksimal **1 jam** pada jam kerja.

## 10) Matriks Eskalasi

- **Level 1 (Operasional)**: Office (validasi data & penambahan item master)
- **Level 2 (Sistem)**: Admin aplikasi (akses, bug, error server)
- **Level 3 (Kebijakan/Final Approval)**: PIC stok/supervisor

## 11) Do & Don't untuk User Field

### Do

- Input sesuai kondisi fisik aktual.
- Eskalasi cepat jika menemukan item baru/anomali.
- Verifikasi notifikasi sukses setelah submit.

### Don't

- Jangan mengakali dengan mengganti item lain.
- Jangan menunda pelaporan anomali.
- Jangan membagikan akun/password.

## 12) Revisi Dokumen

- Versi: 1.0
- Tanggal: 2026-02-14
- Pemilik dokumen: Tim Operasional StockManager
- Ditinjau berkala: minimal 1x per kuartal atau saat ada perubahan alur sistem

