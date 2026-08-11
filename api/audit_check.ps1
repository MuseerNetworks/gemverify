$baseUrl = "http://localhost/gemverify/api"
$db = C:\xampp\php\php.exe -r "require 'C:/xampp/htdocs/gemverify/api/config/database.php'; echo count(db()->query('SELECT * FROM services')->fetchAll());"

Write-Host "Total services in DB: $db"
