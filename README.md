# stockmanager

## Struktur Folder (Optimized)

- `actions/` — endpoint AJAX/API (`autocomplete-items.php`, `delete-item.php`, `update-status.php`)
- `pages/` — implementasi halaman utama aplikasi (dashboard, manajemen, auth)
- `core/` — file shared inti (`functions.php`, `cache_control.php`, `auth_check.php`)
- `config/` — konfigurasi database
- `shared/` — komponen tampilan bersama (`nav.php`, `403.php`)
- `includes/` — (deprecated, tidak dipakai lagi)
- `css/` — stylesheet tambahan khusus modul (contoh: ekspor)
- `cache/`, `logs/`, `sql/` — cache aplikasi, log, dan migrasi/schema SQL

## Peta Arsitektur Singkat

- Entry URL lama di root (`index.php`, `login.php`, dll) berperan sebagai wrapper ke `pages/`.
- Halaman di `pages/` memuat helper dari `core/` lewat wrapper root (`functions.php`, `cache_control.php`).
- Endpoint async dipisah di `actions/` untuk request `fetch`/AJAX.
- Komponen UI bersama berada di `shared/` (navbar, halaman 403).
- Akses database terpusat di `config/database.php`.

Alur request ringkas:
1. Request masuk ke file root (kompatibilitas URL lama).
2. Wrapper meneruskan ke implementasi `pages/*`.
3. Implementasi memanggil helper `core/*` dan koneksi DB.
4. Response dikembalikan sebagai HTML (pages) atau JSON/text (actions).

## Catatan Kompatibilitas

Endpoint menggunakan langsung `actions/*` (wrapper root untuk endpoint lama sudah dihapus karena tidak dipakai internal).

Halaman utama lama di root (`index.php`, `view.php`, `update-stock.php`, `manage-items.php`, `manage-users.php`, `manage-units.php`, `add.php`, `edit-item.php`, `login.php`, `register.php`) tetap tersedia sebagai wrapper ke `pages/`.

Fitur `export-latest.php`, `export-history.php`, dan `usage.php` saat ini dihapus dari menu dan route aktif agar aplikasi fokus ke fitur inti.

File shared lama di root (`functions.php`, `cache_control.php`, `auth_check.php`) tetap tersedia sebagai wrapper ke `core/`.

Folder `includes/` tidak lagi dipakai; source of truth komponen bersama berada di `shared/`.

## Migrasi Soft-Delete (Penting)

Untuk menjaga integritas data historis saat item dihapus, gunakan soft-delete pada tabel `items`.

- Jalankan skrip: `sql/2026-02-14-soft-delete-items.sql`
- Endpoint hapus sekarang mengarsipkan item (`deleted_at`/`deleted_by`), bukan menghapus permanen.
- Seluruh halaman utama dan endpoint filter/autocomplete hanya menampilkan item aktif (`deleted_at IS NULL`).

## Migrasi Konversi Level (Penting)

Untuk memisahkan perhitungan konversi stok berbasis jumlah dan berbasis level:

- Jalankan skrip: `sql/2026-02-14-add-level-conversion.sql`
- Menambahkan kolom baru: `items.level_conversion`
- Form tambah/edit barang kini memiliki input terpisah untuk `unit_conversion` dan `level_conversion`.

## Status Deprecated (Per 2026-02-14)

Elemen berikut telah ditetapkan sebagai **deprecated** dan dipertahankan sementara untuk kompatibilitas transisi:

- Tabel: `products`
- Tabel: `realtime_notifications`
- Kolom: `items.warehouse_stock`
- Kolom: `item_stock_history.warehouse_stock_old`
- Kolom: `item_stock_history.warehouse_stock_new`
- Kolom: `items.calculation_type`

Catatan: aplikasi saat ini tidak lagi menggunakan `warehouse_stock*` sebagai sumber stok aktif, dan `calculation_type` diisi konstan `daily_consumption`.

## Cleanup Legacy Setelah Validasi Produksi

Setelah validasi data produksi selesai, jalankan migration cleanup berikut:

- Cleanup: `sql/2026-02-14-cleanup-legacy-after-prod-validation.sql`
- Rollback: `sql/2026-02-14-cleanup-legacy-after-prod-validation-rollback.sql`

Migration cleanup akan:

- Menghapus orphan table: `products`, `realtime_notifications`
- Menghapus kolom legacy: `items.warehouse_stock`, `items.calculation_type`
- Menghapus kolom legacy: `item_stock_history.warehouse_stock_old`, `item_stock_history.warehouse_stock_new`

Jalankan backup database penuh sebelum eksekusi cleanup pada produksi.