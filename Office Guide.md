# Guide & SOP Pengguna Role **Office**

Dokumen ini adalah panduan operasional lengkap untuk pengguna dengan peran **Office** pada aplikasi StockManager.

---

## 1) Tujuan Dokumen

- Menstandarkan proses kontrol data inventaris dari sisi Office.
- Menjamin kualitas master item dan akurasi parameter perhitungan stok.
- Menetapkan alur validasi, koreksi, dan eskalasi anomali dari tim Field.

---

## 2) Ruang Lingkup Hak Akses Role Office

### Yang bisa dilakukan

| Fitur | Keterangan |
|---|---|
| Login & Dashboard | Akses penuh; nilai sensitif (konversi, konsumsi, coverage) ditampilkan |
| Lihat Data | Lihat seluruh item aktif dengan filter, pencarian, dan pengurutan |
| Pembaruan Stok | Input/koreksi stok lapangan dan nilai level |
| Kelola Barang | Tambah, ubah, arsipkan item; aksi massal; pantau histori audit |
| Tambah Barang | Buat master item baru lengkap dengan semua parameter |
| Ubah Barang | Perbarui semua parameter master item yang sudah ada |

### Yang **tidak** bisa dilakukan

- Mengelola pengguna (khusus Admin).
- Mengelola master satuan (khusus Admin).
- Mengelola master kategori (khusus Admin).
- Menghapus data secara permanen dari database.

---

## 3) Navigasi Aplikasi (Menu Sidebar)

Setelah login, sidebar menampilkan menu berikut untuk role Office:

```
Dashboard          → Ringkasan jumlah stok & pembaruan terbaru
Lihat Data         → Tabel lengkap semua item aktif
Pembaruan Stok     → Form update stok & level lapangan
─── Manajemen ───
Kelola Barang      → Kelola master item (tambah / ubah / arsip)
```

> Menu **Kelola Kategori**, **Kelola Satuan**, dan **Kelola Pengguna** tidak muncul untuk role Office.

---

## 4) Panduan Fitur Per Halaman

### 4.1 Dashboard (`index.php`)

Halaman pertama setelah login. Menampilkan:

- **Kartu ringkasan stok**:
  - Jumlah item `in-stock` (stok aman)
  - Jumlah item `low-stock` / `warning-stock` (mendekati kritis)
  - Jumlah item `out-stock` (habis)
- **Tabel pembaruan terbaru** (8 item terakhir diperbarui): nama, kategori, stok, status, waktu update, dan nama user yang memperbarui.
- **Kolom sensitif** (seperti total stok, days coverage, konversi) **terlihat** untuk Office — berbeda dengan role Field yang melihat tampilan terbatas.

**Aksi yang disarankan dari Dashboard:**

1. Identifikasi item `out-stock` dan `low-stock` setiap awal hari.
2. Klik item yang mencurigakan untuk investigasi lebih lanjut via **Kelola Barang**.

---

### 4.2 Lihat Data (`view.php`)

Menampilkan tabel seluruh item aktif. Fitur yang tersedia:

| Fitur | Cara Penggunaan |
|---|---|
| Filter Kategori | Pilih kategori dari dropdown di atas tabel |
| Filter Status | Pilih status stok dari dropdown |
| Pencarian | Ketik nama item di kotak pencarian |
| Pengurutan | Klik header kolom (Nama, Kategori, Stok, Terakhir Diperbarui) untuk sort ASC/DESC |
| Paginasi | Pilih 10 / 25 / 50 / 100 item per halaman |

Kolom yang ditampilkan untuk Office mencakup nilai lengkap termasuk konversi dan coverage.

---

### 4.3 Pembaruan Stok (`update-stock.php`)

Digunakan untuk mengkoreksi atau memasukkan nilai stok dan level secara manual dari sisi Office.

**Langkah update stok:**

1. Buka menu **Pembaruan Stok**.
2. Filter berdasarkan kategori jika diperlukan.
3. Untuk setiap item yang ingin diperbarui:
   - Ubah nilai **Stok** sesuai kondisi aktual.
   - Jika item memiliki indikator level aktif (`has_level = 1`), ubah nilai **Level** jika ada perubahan.
4. Klik **Simpan** / kirim form.
5. Pastikan notifikasi sukses muncul.

> Office menggunakan halaman ini untuk **koreksi data** saat ada laporan anomali dari Field yang telah tervalidasi. Penggunaan normal lapangan tetap dilakukan oleh user Field.

---

### 4.4 Kelola Barang (`manage-items.php`)

Halaman utama manajemen master item. Hanya dapat diakses oleh Office dan Admin.

#### A. Menambah Item Baru

1. Klik tombol **Tambah Barang** di halaman Kelola Barang.
2. Isi semua field yang diperlukan (lihat **Bagian 6** untuk standar parameter).
3. Klik **Simpan**.
4. Sistem akan menampilkan notifikasi sukses dan item baru muncul di daftar.

> Item juga bisa ditambah melalui halaman terpisah **Tambah Barang** (`add.php`) yang memiliki tampilan form penuh tanpa modal.

#### B. Mengubah Item

1. Temukan item di daftar tabel Kelola Barang.
2. Klik tombol **Ubah** (ikon edit) pada baris item.
3. Formulir ubah item terbuka (modal atau halaman `edit-item.php`).
4. Ubah field yang diperlukan.
5. Klik **Simpan**.

#### C. Mengarsipkan Item (Soft Delete)

Item yang tidak lagi aktif secara operasional dapat **diarsipkan** — bukan dihapus permanen.

- **Per item**: Klik tombol **Arsip** pada baris item.
- **Massal**: Centang beberapa item → pilih aksi **Arsip** dari dropdown aksi massal → klik **Terapkan**.

> Item yang diarsipkan tidak muncul di daftar aktif dan tidak bisa diperbarui stoknya oleh Field.

#### D. Aksi Massal

Tersedia melalui checkbox di kolom kiri tabel:

| Aksi Massal | Fungsi |
|---|---|
| Perbarui Status | Ubah status stok beberapa item sekaligus (`in-stock`, `low-stock`, `warning-stock`, `out-stock`) |
| Perbarui Kategori | Pindahkan beberapa item ke kategori yang sama |
| Arsipkan | Arsipkan beberapa item sekaligus |

**Cara menggunakan aksi massal:**
1. Centang item yang ingin diproses (atau gunakan "pilih semua").
2. Pilih aksi dari dropdown **Aksi Massal**.
3. Pilih nilai target (status/kategori) jika diperlukan.
4. Klik **Terapkan**.

#### E. Histori Audit (Feed)

Bagian bawah halaman Kelola Barang menampilkan **15 entri perubahan terbaru** dari semua item, meliputi:

- Nama item yang diubah
- Jenis perubahan (Stok Lapangan, Status, Total Stok, Ketahanan Hari, Catatan, Item Baru, Arsip)
- Nilai sebelum dan sesudah
- Waktu perubahan
- Username pelaku perubahan

Feed ini diperbarui secara otomatis (polling) saat ada perubahan baru.

---

## 5) Tanggung Jawab Inti Role Office

1. Menjaga kualitas master item agar sesuai kondisi operasional lapangan.
2. Memvalidasi laporan anomali dari user Field.
3. Menetapkan parameter item (konversi, mode, konsumsi, minimum coverage) dengan benar.
4. Memastikan perubahan penting tercatat dan dapat diaudit.
5. Menjadi penghubung eskalasi antara Field dan Admin/PIC.

---

## 6) Alur Kerja Harian Role Office

### A. Monitoring awal hari

1. Login ke aplikasi.
2. Cek **Dashboard** — identifikasi item `out-stock` dan `low-stock`.
3. Buka **Lihat Data** untuk review menyeluruh jika diperlukan.
4. Prioritaskan tindakan berdasarkan dampak operasional.

### B. Validasi update dari Field

1. Buka **Pembaruan Stok** atau **Lihat Data**.
2. Review perubahan signifikan (lonjakan/penurunan ekstrem).
3. Jika data wajar → lanjutkan pemantauan.
4. Jika data tidak wajar → klarifikasi ke Field sebelum koreksi.

### C. Pemeliharaan master item

1. Buka **Kelola Barang**.
2. Tambah item baru dari anomali Field yang tervalidasi.
3. Perbarui parameter item bila ada perubahan proses bisnis.
4. Arsipkan item yang tidak lagi aktif.

---

## 7) SOP Penanganan Anomali dari Field

### 7.1 Kasus: Item fisik belum terdaftar

#### Input dari Field (wajib diterima sebelum tindakan)

- Nama item
- Kategori usulan
- Satuan usulan
- Estimasi stok
- Lokasi dan waktu temuan

#### Langkah Office

1. Cek apakah item sudah ada di sistem (cari di **Lihat Data** / **Kelola Barang** berdasarkan nama/satuan/kategori serupa).
2. Jika duplikasi → arahkan Field ke item yang benar.
3. Jika item benar-benar baru:
   - Buat master item via **Kelola Barang** → **Tambah Barang**.
   - Isi parameter minimal valid (lihat Bagian 8).
4. Informasikan kembali ke Field bahwa item sudah tersedia.
5. Dokumentasikan di kanal operasional tim.

**SLA disarankan: maksimal 30 menit** sejak laporan diterima pada jam kerja.

---

### 7.2 Kasus: Data stok tidak wajar

1. Tahan keputusan koreksi final sampai ada konfirmasi dari Field.
2. Cek histori perubahan item di feed audit **Kelola Barang**.
3. Jika kesalahan terkonfirmasi → lakukan koreksi terkontrol melalui **Pembaruan Stok**.
4. Catat penyebab (mis-input, salah item, salah pembacaan level, dll) di kanal operasional.

---

### 7.3 Kasus: Error sistem saat update / master data

1. Simpan screenshot dan waktu kejadian.
2. Ulangi aksi 1 kali setelah refresh halaman.
3. Jika tetap gagal, eskalasi ke Admin dengan detail minimum:
   - Halaman / proses yang gagal
   - Item yang terlibat
   - Input yang dikirim
   - Pesan error yang tampil

---

## 8) Standar Parameter Master Item (Wajib)

Saat menambah atau mengubah item, Office **wajib** mengikuti standar berikut.

### 8.1 Parameter umum

| Field | Aturan |
|---|---|
| Nama (`name`) | Unik secara operasional; hindari nama ambigu atau duplikasi |
| Kategori (`category`) | Wajib sesuai master kategori aktif |
| Satuan (`unit`) | Wajib sesuai master satuan aktif |
| Stok Lapangan (`field_stock`) | Integer ≥ 0 |
| Konsumsi Harian (`daily_consumption`) | Angka ≥ 0 |
| Min Hari Coverage (`min_days_coverage`) | Integer ≥ 1 |

### 8.2 Parameter level & mode perhitungan

**Item tanpa level** (`has_level = 0`):
- `calculation_mode` harus `combined`.
- Field `level` dibiarkan kosong.

**Item dengan level** (`has_level = 1`):
- `level` wajib integer ≥ 0.
- `calculation_mode` boleh `combined` atau `multiplied`.

**Mode `combined`:**
- Gunakan `unit_conversion` dan `level_conversion` sesuai kalibrasi.
- Rumus: `(level × level_conversion) + (field_stock × unit_conversion)`

**Mode `multiplied`:**
- Gunakan faktor konversi kustom.
- Rumus: `custom_factor × level × field_stock`

### 8.3 Aturan kualitas parameter

- Semua faktor konversi harus **> 0**.
- Jangan mengubah banyak parameter sekaligus tanpa alasan operasional yang jelas.
- Setiap perubahan parameter kritikal harus dapat ditelusuri melalui histori audit.

---

## 9) Checklist Validasi Sebelum Simpan Perubahan Master

Wajib diverifikasi Office sebelum klik **Simpan**:

- [ ] Nama item tidak duplikat atau ambigu.
- [ ] Kategori dan satuan benar dan masih aktif.
- [ ] Mode perhitungan sesuai jenis item (ada/tidak ada level).
- [ ] Faktor konversi masuk akal (hasil kalibrasi, bukan angka asal).
- [ ] Konsumsi harian realistis berdasarkan kondisi operasional.
- [ ] Min days coverage sesuai tingkat kritikal item.
- [ ] Dampak status stok setelah perubahan sudah dipahami.

---

## 10) Standar Audit & Dokumentasi

- Gunakan catatan operasional untuk setiap perubahan penting (terutama hasil tindak lanjut anomali Field).
- Pantau **feed audit** di halaman **Kelola Barang** secara berkala.
- Pastikan histori perubahan item dapat menjawab:
  - Siapa yang mengubah
  - Kapan diubah
  - Nilai sebelum / sesudah
  - Alasan perubahan

---

## 11) Referensi Status Stok

| Status | Label | Kondisi |
|---|---|---|
| `in-stock` | Tersedia | Stok aman (coverage > min coverage) |
| `warning-stock` | Peringatan | Stok di atas minimum tetapi mendekati batas |
| `low-stock` | Stok Rendah | Stok di bawah atau sekitar ambang minimum |
| `out-stock` | Habis | Coverage ≤ 0 atau stok tidak mencukupi |

---

## 12) Matriks Eskalasi Role Office

| Tujuan Eskalasi | Kondisi |
|---|---|
| **Field** | Klarifikasi data fisik, verifikasi kondisi lapangan |
| **Admin** | Error sistem, masalah akses, perubahan master global (user/satuan/kategori) |
| **PIC / Supervisor** | Konflik keputusan operasional, prioritas restock, approval kebijakan |

---

## 13) SLA Operasional Office (Disarankan)

| Skenario | Target Waktu |
|---|---|
| Verifikasi anomali item baru | ≤ 30 menit sejak laporan Field |
| Koreksi master item kritikal | ≤ 1 jam |
| Respons eskalasi error sistem ke Admin | ≤ 15 menit |
| Update tindak lanjut ke Field | ≤ 30 menit setelah keputusan |

---

## 14) Do & Don't untuk Role Office

### Do ✓

- Validasi data sebelum mengubah master item.
- Prioritaskan item `out-stock` dan `low-stock` lebih dulu.
- Komunikasikan tindak lanjut kembali ke Field setelah keputusan diambil.
- Gunakan pendekatan konservatif saat data meragukan (tahan dahulu, konfirmasi dulu).
- Pantau feed audit berkala untuk deteksi dini anomali.

### Don't ✗

- Jangan tambah item baru tanpa verifikasi minimum dari Field.
- Jangan ubah parameter kritikal (konversi, mode, konsumsi) tanpa memahami dampaknya pada status stok.
- Jangan menunda tindak lanjut anomali yang memengaruhi operasi lapangan.
- Jangan arsipkan item aktif tanpa koordinasi dengan Field dan Admin.

---

## 15) Lampiran Template (Siap Pakai)

### Template verifikasi anomali item baru

```
[VERIFIKASI ANOMALI - OFFICE]
Tanggal/Waktu      :
Pelapor (Field)    :
Nama item usulan   :
Hasil cek duplikasi: [ADA DUPLIKASI / TIDAK ADA]
Keputusan          : [DITERIMA BARU / GUNAKAN ITEM EKSISTING / DITOLAK]
Tindak lanjut      :
PIC Office         :
```

### Template klarifikasi selisih stok

```
[KLARIFIKASI SELISIH STOK - OFFICE]
Item                   :
Nilai sistem sebelumnya:
Nilai input terbaru    :
Selisih                :
Konfirmasi dari Field  :
Keputusan Office       :
```

### Template eskalasi error sistem ke Admin

```
[ESKALASI ERROR SISTEM]
Tanggal/Waktu  :
Halaman        :
Proses/aksi    :
Item terlibat  :
Input dikirim  :
Pesan error    :
Screenshot     : [terlampir]
PIC Office     :
```

---

## 16) Revisi Dokumen

- Versi: 1.1
- Tanggal: 2026-02-23
- Pemilik dokumen: Tim Operasional StockManager
- Review berkala: minimal 1x per kuartal atau saat ada perubahan fitur/hak akses

