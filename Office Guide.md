# Guide & SOP Pengguna Role **Office**

Dokumen ini adalah panduan operasional lengkap untuk pengguna dengan peran **Office** pada aplikasi StockManager.

## 1) Tujuan Dokumen

- Menstandarkan proses kontrol data inventaris dari sisi Office.
- Menjamin kualitas master item dan akurasi parameter perhitungan stok.
- Menetapkan alur validasi, koreksi, dan eskalasi anomali dari tim Field.

## 2) Ruang Lingkup Hak Akses Role Office

### Yang bisa dilakukan

- Login dan mengakses dashboard.
- Melihat informasi stok detail (termasuk nilai sensitif yang ditampilkan untuk Office/Admin).
- Memperbarui stok dari halaman **Perbarui Stok**.
- Menambah dan mengubah item dari halaman **Kelola Barang / Manage Items**.

### Yang tidak bisa dilakukan

- Mengelola user (khusus Admin).
- Mengelola master unit (khusus Admin).
- Mengelola master kategori (khusus Admin).
- Menghapus data fisik di database secara permanen.

## 3) Tanggung Jawab Inti Role Office

1. Menjaga kualitas master item agar sesuai kondisi operasional lapangan.
2. Memvalidasi laporan anomali dari user Field.
3. Menetapkan parameter item (konversi, mode, konsumsi, minimum coverage) dengan benar.
4. Memastikan perubahan penting tercatat dan dapat diaudit.
5. Menjadi penghubung eskalasi antara Field dan Admin/PIC.

## 4) Alur Kerja Harian Role Office

### A. Monitoring awal hari

1. Login ke aplikasi.
2. Cek dashboard untuk item berstatus kritikal (`out-stock`, `low-stock`).
3. Prioritaskan item berdasarkan dampak operasional.

### B. Validasi update dari Field

1. Buka **Perbarui Stok** / **Lihat Stok**.
2. Review perubahan signifikan (lonjakan/penurunan ekstrem).
3. Jika data wajar, lanjutkan pemantauan.
4. Jika data tidak wajar, lakukan klarifikasi ke Field sebelum koreksi.

### C. Pemeliharaan master item

1. Buka **Kelola Barang**.
2. Tambah item baru dari anomali Field yang tervalidasi.
3. Perbarui parameter item bila ada perubahan proses bisnis.
4. Pastikan status item aktif/arsip sesuai kondisi operasional.

## 5) SOP Penanganan Anomali dari Field

## 5.1 Kasus: item fisik belum terdaftar

### Input dari Field (wajib diterima)

- Nama item
- Kategori usulan
- Satuan usulan
- Estimasi stok
- Lokasi dan waktu temuan

### Langkah Office

1. Verifikasi apakah item benar-benar belum ada di sistem (cek nama/satuan/kategori serupa).
2. Jika ternyata duplikasi, arahkan Field ke item yang benar.
3. Jika item benar-benar baru:
	 - Buat master item via **Kelola Barang**.
	 - Isi parameter minimal valid (lihat bagian 6).
4. Informasikan kembali ke Field bahwa item sudah tersedia untuk update stok.
5. Dokumentasikan nomor/jejak eskalasi di kanal operasional tim.

SLA disarankan: maksimal **30 menit** sejak laporan diterima pada jam kerja.

## 5.2 Kasus: data stok tidak wajar

1. Tahan keputusan koreksi final sampai ada konfirmasi Field.
2. Cek histori perubahan item.
3. Jika kesalahan input terkonfirmasi, lakukan koreksi terkontrol.
4. Catat penyebab (mis-input, salah item, salah pembacaan level, dll).

## 5.3 Kasus: error sistem saat update/master data

1. Simpan screenshot dan waktu kejadian.
2. Ulangi aksi 1 kali setelah refresh.
3. Jika tetap gagal, eskalasi ke Admin dengan detail teknis minimum:
	 - Halaman/proses yang gagal
	 - Item yang terlibat
	 - Input yang dikirim
	 - Pesan error

## 6) Standar Parameter Master Item (Wajib)

Saat menambah/mengubah item, Office wajib mengikuti standar berikut:

### 6.1 Parameter umum

- `name`: wajib unik secara operasional (hindari nama ambigu).
- `category`: wajib sesuai master kategori aktif.
- `unit`: wajib sesuai master satuan aktif.
- `field_stock`: integer, >= 0.
- `daily_consumption`: angka >= 0.
- `min_days_coverage`: integer >= 1.

### 6.2 Parameter level & mode perhitungan

- `has_level = 0`:
	- `calculation_mode` harus `combined`.
	- `level` tidak diisi.
- `has_level = 1`:
	- `level` wajib integer >= 0.
	- `calculation_mode` boleh `combined` atau `multiplied`.

Mode `combined`:

- Gunakan `unit_conversion` dan `level_conversion` sesuai hasil kalibrasi.
- Rumus: `(level × level_conversion) + (field_stock × unit_conversion)`.

Mode `multiplied`:

- Gunakan faktor konversi kustom (di UI), efektif untuk rumus multiplied.
- Rumus: `custom_factor × level × field_stock`.

### 6.3 Aturan kualitas parameter

- Semua faktor konversi harus > 0.
- Jangan mengubah banyak parameter sekaligus tanpa alasan operasional jelas.
- Setiap perubahan parameter kritikal harus dapat ditelusuri dari histori.

## 7) Standar Validasi Sebelum Simpan Perubahan Master

Checklist wajib Office sebelum klik simpan:

- [ ] Nama item tidak duplikat/ambigu.
- [ ] Kategori dan satuan benar.
- [ ] Mode perhitungan sesuai jenis item.
- [ ] Konversi dan konsumsi harian masuk akal.
- [ ] Min days coverage sesuai tingkat kritikal item.
- [ ] Dampak status stok setelah perubahan sudah dipahami.

## 8) Standar Audit & Dokumentasi

- Gunakan catatan operasional untuk perubahan penting (terutama hasil anomali Field).
- Pastikan histori update item dapat menjelaskan:
	- Siapa yang mengubah
	- Kapan diubah
	- Nilai sebelum/sesudah
	- Alasan perubahan (bila tersedia)

## 9) Matriks Eskalasi Role Office

- **Ke Field**: klarifikasi data fisik dan verifikasi ulang lapangan.
- **Ke Admin**: error sistem, akses, kendala teknis, atau kebutuhan perubahan master global (user/unit/kategori).
- **Ke PIC/Supervisor**: konflik keputusan operasional, prioritas restock, dan approval kebijakan.

## 10) SLA Operasional Office (Disarankan)

- Verifikasi anomali item baru: <= 30 menit.
- Koreksi master item kritikal: <= 1 jam.
- Respons eskalasi error sistem: <= 15 menit.
- Update tindak lanjut ke Field: <= 30 menit setelah keputusan.

## 11) Do & Don't untuk Role Office

### Do

- Validasi data sebelum ubah master.
- Prioritaskan item kritikal lebih dulu.
- Komunikasi balik ke Field setelah tindak lanjut.
- Gunakan pendekatan konservatif saat data meragukan.

### Don't

- Jangan approve item baru tanpa verifikasi minimum.
- Jangan ubah parameter kritikal tanpa memahami dampak status stok.
- Jangan menunda tindak lanjut anomali yang memengaruhi operasi.

## 12) Lampiran Template (Siap Pakai)

### Template verifikasi anomali item baru

```
[VERIFIKASI ANOMALI - OFFICE]
Tanggal/Waktu:
Pelapor (Field):
Nama item usulan:
Hasil cek duplikasi:
Keputusan: [DITERIMA BARU / GUNAKAN ITEM EKSISTING / DITOLAK]
Tindak lanjut:
PIC Office:
```

### Template klarifikasi selisih stok

```
[KLARIFIKASI SELISIH STOK]
Item:
Nilai sistem sebelumnya:
Nilai input terbaru:
Selisih:
Alasan dari Field:
Keputusan Office:
```

## 13) Revisi Dokumen

- Versi: 1.0
- Tanggal: 2026-02-14
- Pemilik dokumen: Tim Operasional StockManager
- Review berkala: minimal 1x per kuartal atau saat ada perubahan fitur/hak akses

