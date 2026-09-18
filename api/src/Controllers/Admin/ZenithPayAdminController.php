<?php
namespace Controllers\Admin;

use Helpers\Response;
use Middleware\AdminMiddleware;
use PDO;

final class ZenithPayAdminController
{
    public function settings(): void {
        AdminMiddleware::requireRole('super_admin');
        $rows = db()->query("SELECT setting_key, setting_value, updated_at FROM payment_gateway_settings WHERE setting_key IN ('katpay_funding_enabled','zenithpay_activation_enabled')")->fetchAll(PDO::FETCH_ASSOC);
        Response::success(['settings' => $rows]);
    }
    public function updateSettings(): void {
        AdminMiddleware::requireRole('super_admin');
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $allowed = ['katpay_funding_enabled', 'zenithpay_activation_enabled'];
        $db = db(); $adminId = AdminMiddleware::getAdminId(); $updated = [];
        foreach ($allowed as $key) if (array_key_exists($key, $data)) {
            $value = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            $db->prepare('INSERT INTO payment_gateway_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()')->execute([$key,$value,$adminId]);
            $updated[$key] = $value === '1';
        }
        Response::success(['settings'=>$updated], 'Payment settings updated.');
    }
    public function deposits(): void {
        AdminMiddleware::requireRole('super_admin');
        $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??30))); $offset=($page-1)*$limit;
        $db=db(); $status=trim((string)($_GET['status']??'')); $where=$status!==''?'WHERE d.processing_status = ?':'';
        $count=$db->prepare("SELECT COUNT(*) FROM zenithpay_deposits d $where"); $count->execute($status!==''?[$status]:[]);
        $q=$db->prepare("SELECT d.*, u.business_name, a.account_name FROM zenithpay_deposits d LEFT JOIN users u ON u.id=d.user_id LEFT JOIN zenithpay_virtual_accounts a ON a.id=d.zenithpay_virtual_account_id $where ORDER BY d.id DESC LIMIT $limit OFFSET $offset"); $q->execute($status!==''?[$status]:[]);
        Response::success(['data'=>$q->fetchAll(PDO::FETCH_ASSOC),'pagination'=>['current_page'=>$page,'per_page'=>$limit,'total'=>(int)$count->fetchColumn()]]);
    }
    public function recoverAccount(): void {
        AdminMiddleware::requireRole('super_admin');
        $d=json_decode(file_get_contents('php://input'),true)?:[]; $uid=(int)($d['user_id']??0);
        if ($uid<1 || empty($d['account_reference']) || empty($d['account_number']) || empty($d['account_name']) || empty($d['bank_name'])) { Response::error('User and complete account details are required.',422); return; }
        $db=db(); $user=$db->prepare('SELECT email FROM users WHERE id=?');$user->execute([$uid]);$email=$user->fetchColumn(); if(!$email){Response::error('User not found.',404);return;}
        try { $db->prepare("INSERT INTO zenithpay_virtual_accounts (user_id,account_reference,account_number,account_name,bank_name,customer_email,status,created_at,updated_at) VALUES (?,?,?,?,?,?,'active',NOW(),NOW()) ON DUPLICATE KEY UPDATE account_reference=VALUES(account_reference),account_number=VALUES(account_number),account_name=VALUES(account_name),bank_name=VALUES(bank_name),customer_email=VALUES(customer_email),status='active',last_error=NULL,updated_at=NOW()")->execute([$uid,trim($d['account_reference']),trim($d['account_number']),trim($d['account_name']),trim($d['bank_name']),$email]); Response::success([], 'ZenithPay account recovered.'); }
        catch(\Throwable $e){Response::error('Could not save this account. Check that its reference and number are not assigned to another user.',409);}
    }
}
