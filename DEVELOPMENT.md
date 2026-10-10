# Phát triển Quick OTP Mail PHP

```bash
cd quickotp-private
composer install --no-dev --prefer-dist
cd ..
php tests/unit.php
php tests/updater.php
node tests/system-ui.js
node tests/i18n.js
python3 tests/maintenance.py
python3 tests/integration.py
php -S 127.0.0.1:8080 -t public_html tools/router.php
```

Kiểm thử tích hợp mặc định tạo một MariaDB riêng trong thư mục tạm và tự dừng sau khi chạy. Cần `mariadb-install-db`, `mariadbd`, `mariadb`, Python 3 và PHP. The release-link regression test also needs Node.js 20 or later. Có thể chỉ định database thử bằng các biến `QUICKOTP_TEST_DB_HOST`, `QUICKOTP_TEST_DB_PORT`, `QUICKOTP_TEST_DB_NAME`, `QUICKOTP_TEST_DB_USER`, `QUICKOTP_TEST_DB_PASSWORD`; tên database phải bắt đầu bằng `quickotp_test_`.

Database thử chỉ định cần trống. Nếu muốn chạy lại trên cùng database thử, đặt `QUICKOTP_TEST_RESET_DATABASE=1` để xóa các bảng `qotp_*` trước khi chạy. Chỉ dùng cờ này với database dành riêng cho kiểm thử; CI sử dụng cờ này cho hai lượt source và ZIP.

Đóng gói:

```bash
python3 tools/package.py
```

Source PHP nằm ngay ở thư mục gốc của repository riêng. `config.php`, `install-password.php`, `install-token.txt`, nội dung `storage`, database, file backup và thư viện `vendor` không commit. ZIP Release được build sạch và có sẵn `vendor` từ `composer.lock`.

Trước khi đưa lên GitHub, kiểm tra không có tài khoản/mật khẩu thật, khóa, token, cấu hình đang chạy, database, backup hoặc file hướng dẫn cục bộ. Chỉ dùng dữ liệu mẫu trong tài liệu. Khi đổi phiên bản, cập nhật `VERSION`, `System::VERSION`, phiên bản asset, tiêu đề README và CHANGELOG; bộ đóng gói sẽ dừng nếu các phiên bản không khớp. Footer lấy phiên bản từ ứng dụng. Cập nhật lệnh tải/clone trong README khi Release mới đã được phát hành.

The package builder creates two ZIPs. `-website.zip` has no wrapper directory and is extracted into the website home directory, creating sibling `public_html/` and `quickotp-private/` directories. The document root remains `public_html/`. Matching application files in an existing public directory are replaced; runtime configuration and storage data are excluded from packages. The canonical ZIP retains virtual `public_html/` and `quickotp-private/` paths for compatibility with split-directory installations. Both checksums are in `checksums-php.txt`.

Test both package formats; tests/webroot.py also covers the legacy private directory inside the document root:

```bash
python3 tools/package.py
python3 tests/webroot.py
QUICKOTP_TEST_PACKAGE="$PWD/dist/Quick-Otp-Email-Check-PHP_v$(cat VERSION).zip" python3 tests/integration.py
QUICKOTP_TEST_PACKAGE="$PWD/dist/Quick-Otp-Email-Check-PHP_v$(cat VERSION)-website.zip" python3 tests/integration.py
```

The current version uses `v1.0.0-beta-6`; discovery also accepts legacy `v1.0.0-beta_1` tags. Fresh setup needs no installation-password file or token, including in source checkouts. Legacy secret files stay ignored and must never be included in an archive or commit. `VERSION`, application version, asset versions, installer/footer and release metadata use the same version identifier. Publish beta tags as GitHub prereleases. Beta clients accept later beta versions and stable versions; stable clients do not install beta versions.
