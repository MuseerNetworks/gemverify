<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = db();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Seed Categories
    echo "Seeding 6 service categories...\n";
    $categories = [
        ['name' => 'NIN', 'slug' => 'nin', 'sort_order' => 1],
        ['name' => 'BVN', 'slug' => 'bvn', 'sort_order' => 2],
        ['name' => 'JAMB', 'slug' => 'jamb', 'sort_order' => 3],
        ['name' => 'CAC', 'slug' => 'cac', 'sort_order' => 4],
        ['name' => 'TIN', 'slug' => 'tin', 'sort_order' => 5],
        ['name' => 'ATTESTATION', 'slug' => 'attestation', 'sort_order' => 6]
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO service_categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)");
    foreach ($categories as $cat) {
        $stmt->execute($cat);
    }

    $catMap = [];
    foreach ($pdo->query("SELECT id, slug FROM service_categories") as $row) {
        $catMap[$row['slug']] = $row['id'];
    }

    // 2. Seed All 22 Services & All 40 Pricing Variants
    echo "Seeding 22 manual services & 40 pricing variants...\n";
    $services = [
        // NIN Category
        [
            'category_slug' => 'nin',
            'name' => 'NIN Enrollment',
            'slug' => 'nin-enrollment',
            'description' => 'New NIN Enrollment for Adult and Child',
            'est_time' => '1-7 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 2000]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'NIN Verification',
            'slug' => 'nin-verification',
            'description' => 'Verify NIN records instantly via slip or search',
            'est_time' => 'Instant',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => 'basic', 'variant_label' => 'Basic Slip', 'price' => 250],
                ['variant_key' => 'regular', 'variant_label' => 'Regular Slip', 'price' => 350],
                ['variant_key' => 'standard', 'variant_label' => 'Standard Slip', 'price' => 500],
                ['variant_key' => 'premium', 'variant_label' => 'Premium Slip', 'price' => 800],
                ['variant_key' => 'vnin', 'variant_label' => 'vNIN Slip', 'price' => 1000]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'NIN Validation Bulk',
            'slug' => 'nin-validation',
            'description' => 'Bulk NIN Validation service',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => 'No Record Found — ₦300', 'variant_label' => 'No Record Found', 'price' => 300],
                ['variant_key' => 'SIM Validation — ₦200', 'variant_label' => 'SIM Validation', 'price' => 200],
                ['variant_key' => 'v.nin validation — ₦250', 'variant_label' => 'v.nin validation', 'price' => 250],
                ['variant_key' => 'Update Records Validation — ₦400', 'variant_label' => 'Update Records Validation', 'price' => 400],
                ['variant_key' => 'Bank Validation — ₦300', 'variant_label' => 'Bank Validation', 'price' => 300],
                ['variant_key' => 'Modification Validation — ₦350', 'variant_label' => 'Modification Validation', 'price' => 350],
                ['variant_key' => 'Photographic Error — ₦300', 'variant_label' => 'Photographic Error', 'price' => 300]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'IPE Modification',
            'slug' => 'ipe-modification',
            'description' => 'NIN IPE Clearance and Modification',
            'est_time' => '2-5 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1200]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'IPE Clearance — Bulk',
            'slug' => 'ipe-clearance',
            'description' => 'Bulk IPE Clearance Processing',
            'est_time' => 'Instant',
            'is_manual' => 1,
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Per Tracking ID', 'price' => 500]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'IPE Clearance — Single',
            'slug' => 'ipe-clearance-single',
            'description' => 'Single IPE Clearance Processing',
            'est_time' => 'Instant',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 500]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'NIN Personalisation',
            'slug' => 'personalization',
            'description' => 'NIN Personalisation Processing',
            'est_time' => '1-3 days',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 800]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'NIN Modification',
            'slug' => 'nin-modification',
            'description' => 'Modify Name, Phone, DOB or Address on NIN',
            'est_time' => '3-7 days',
            'pricing' => [
                ['variant_key' => 'Name', 'variant_label' => 'Name Modification', 'price' => 1500],
                ['variant_key' => 'Phone', 'variant_label' => 'Phone Modification', 'price' => 800],
                ['variant_key' => 'DOB', 'variant_label' => 'DOB Modification', 'price' => 5000],
                ['variant_key' => 'Address', 'variant_label' => 'Address Modification', 'price' => 1200],
                ['variant_key' => 'Name&DOB', 'variant_label' => 'Name & DOB Modification', 'price' => 6000],
                ['variant_key' => 'Name&Phone', 'variant_label' => 'Name & Phone Modification', 'price' => 2000]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'DOB More Than 5 Years',
            'slug' => 'dob-correction',
            'description' => 'DOB Correction above 5 years tier',
            'est_time' => '5-15 days',
            'pricing' => [
                ['variant_key' => 'Above 5 Years', 'variant_label' => 'Above 5 Years Tier', 'price' => 10000],
                ['variant_key' => 'Above 10 Years', 'variant_label' => 'Above 10 Years Tier', 'price' => 15000],
                ['variant_key' => 'Above 15 Years', 'variant_label' => 'Above 15 Years Tier', 'price' => 20000]
            ]
        ],
        [
            'category_slug' => 'nin',
            'name' => 'Self-Service Delinking',
            'slug' => 'self-service',
            'description' => 'Delinking Email or Retrieval of NIN Details',
            'est_time' => 'Instant',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => 'Delinking Email', 'variant_label' => 'Delinking Email', 'price' => 500],
                ['variant_key' => 'Retrieval NIN Details', 'variant_label' => 'Retrieval NIN Details', 'price' => 500]
            ]
        ],


        // BVN Category
        [
            'category_slug' => 'bvn',
            'name' => 'BVN Verification',
            'slug' => 'bvn-verification',
            'description' => 'Verify BVN details via slip or search',
            'est_time' => 'Instant',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => 'full', 'variant_label' => 'Full Details Slip', 'price' => 400],
                ['variant_key' => 'premium', 'variant_label' => 'Premium Slip', 'price' => 700]
            ]
        ],
        [
            'category_slug' => 'bvn',
            'name' => 'BVN Retrieval',
            'slug' => 'bvn-retrieval',
            'description' => 'Retrieve BVN details by name/phone',
            'est_time' => 'Instant',
            'is_manual' => 0,
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 400]
            ]
        ],
        [
            'category_slug' => 'bvn',
            'name' => 'BVN License Creation',
            'slug' => 'bvn-license',
            'description' => 'Create or Re-issue BVN License',
            'est_time' => '1-3 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 3000]
            ]
        ],
        [
            'category_slug' => 'bvn',
            'name' => 'Non-Appearance Enrollment',
            'slug' => 'bvn-nonappearance',
            'description' => 'Non-Appearance BVN Enrollment',
            'est_time' => '1-7 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 25000]
            ]
        ],
        [
            'category_slug' => 'bvn',
            'name' => 'Central Risk Management',
            'slug' => 'bvn-risk',
            'description' => 'Central Risk Management Resolution',
            'est_time' => '24-48 hrs',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 2000]
            ]
        ],
        [
            'category_slug' => 'bvn',
            'name' => 'BVN Modification',
            'slug' => 'bvn-modification',
            'description' => 'Update Name, Phone, DOB or Address on BVN',
            'est_time' => '3-7 days',
            'pricing' => [
                ['variant_key' => 'Update Name', 'variant_label' => 'Update Name', 'price' => 1500],
                ['variant_key' => 'Update Phone', 'variant_label' => 'Update Phone', 'price' => 800],
                ['variant_key' => 'Update DOB', 'variant_label' => 'Update DOB', 'price' => 5000],
                ['variant_key' => 'Update Address', 'variant_label' => 'Update Address', 'price' => 1200]
            ]
        ],

        // JAMB Category
        [
            'category_slug' => 'jamb',
            'name' => 'JAMB Original Result Slip',
            'slug' => 'jamb-original-result',
            'description' => 'Original Result Slip Print Out',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1500]
            ]
        ],
        [
            'category_slug' => 'jamb',
            'name' => 'JAMB 2026 Exam Slip Printing',
            'slug' => 'jamb-2026-slip',
            'description' => 'Reprint 2026 UTME Exam Slip',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
            ]
        ],
        [
            'category_slug' => 'jamb',
            'name' => 'JAMB Admission Letter Print Out',
            'slug' => 'jamb-admission-letter',
            'description' => 'Original Admission Letter Print Out',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1500]
            ]
        ],
        [
            'category_slug' => 'jamb',
            'name' => 'JAMB Re-Prints / Other Services',
            'slug' => 'jamb-reprints',
            'description' => 'JAMB Re-Prints and related processing',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
            ]
        ],
        [
            'category_slug' => 'jamb',
            'name' => 'JAMB Reprint Original Result Slip',
            'slug' => 'jamb-reprint-original',
            'description' => 'Reprint Original Result Slip',
            'est_time' => 'Instant',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 1000]
            ]
        ],

        // CAC Category
        [
            'category_slug' => 'cac',
            'name' => 'Business Name Registration',
            'slug' => 'cac-business',
            'description' => 'CAC Business Name Registration',
            'est_time' => '3-5 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 25000]
            ]
        ],
        [
            'category_slug' => 'cac',
            'name' => 'Company LTD Setup',
            'slug' => 'cac-ltd',
            'description' => 'CAC Company Limited Registration',
            'est_time' => '5-7 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 45000]
            ]
        ],

        // TIN Category
        [
            'category_slug' => 'tin',
            'name' => 'TIN Registration',
            'slug' => 'tin-registration',
            'description' => 'Individual or Company Tax ID Registration',
            'est_time' => '1-3 days',
            'pricing' => [
                ['variant_key' => 'Individual TIN', 'variant_label' => 'Individual TIN', 'price' => 1500],
                ['variant_key' => 'Company TIN', 'variant_label' => 'Company TIN', 'price' => 1500]
            ]
        ],

        // Attestation Category
        [
            'category_slug' => 'attestation',
            'name' => 'NIN Attestation',
            'slug' => 'nin-attestation',
            'description' => 'Official NIN Attestation Document',
            'est_time' => '5-7 days',
            'pricing' => [
                ['variant_key' => null, 'variant_label' => 'Standard Fee', 'price' => 17000]
            ]
        ]
    ];

    $svcStmt = $pdo->prepare("INSERT INTO services (category_id, name, slug, description, est_time, is_manual) 
                              VALUES (:category_id, :name, :slug, :description, :est_time, :is_manual)
                              ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), est_time=VALUES(est_time), is_manual=VALUES(is_manual)");

    $priceStmt = $pdo->prepare("INSERT INTO service_pricing (service_id, variant_key, variant_label, price) 
                                VALUES (:service_id, :variant_key, :variant_label, :price)
                                ON DUPLICATE KEY UPDATE price=VALUES(price), variant_label=VALUES(variant_label)");

    foreach ($services as $svc) {
        $svcStmt->execute([
            'category_id' => $catMap[$svc['category_slug']],
            'name' => $svc['name'],
            'slug' => $svc['slug'],
            'description' => $svc['description'],
            'est_time' => $svc['est_time'],
            'is_manual' => $svc['is_manual'] ?? 1
        ]);
        
        $serviceId = $pdo->query("SELECT id FROM services WHERE slug = '{$svc['slug']}'")->fetchColumn();

        foreach ($svc['pricing'] as $price) {
            // Delete existing pricing for exact matching service and variant before re-inserting
            if ($price['variant_key'] === null) {
                $pdo->exec("DELETE FROM service_pricing WHERE service_id = $serviceId AND variant_key IS NULL");
            } else {
                $pdo->exec("DELETE FROM service_pricing WHERE service_id = $serviceId AND variant_key = " . $pdo->quote($price['variant_key']));
            }

            $priceStmt->execute([
                'service_id' => $serviceId,
                'variant_key' => $price['variant_key'],
                'variant_label' => $price['variant_label'],
                'price' => $price['price']
            ]);
        }
    }

    // 3. Seed Admins with Roles
    echo "Seeding Admin Accounts with Roles...\n";
    $admins = [
        ['name' => 'GemVerify Super Admin', 'email' => 'admin@gemverify.com', 'password' => 'Admin@2026', 'role' => 'super_admin'],
        ['name' => 'GemVerify Operations Admin', 'email' => 'ops@gemverify.com', 'password' => 'Admin@2026', 'role' => 'admin'],
        ['name' => 'GemVerify Support Staff', 'email' => 'support@gemverify.com', 'password' => 'Support@2026', 'role' => 'support']
    ];

    $stmtAdmin = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) 
                                VALUES (:name, :email, :password, :role)
                                ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role)");

    foreach ($admins as $adm) {
        $stmtAdmin->execute([
            'name' => $adm['name'],
            'email' => $adm['email'],
            'password' => password_hash($adm['password'], PASSWORD_DEFAULT),
            'role' => $adm['role']
        ]);
    }

    $svcCount = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    $priceCount = $pdo->query("SELECT COUNT(*) FROM service_pricing")->fetchColumn();

    echo "\nSEEDING COMPLETE SUCCESS!\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Total Services Seeded: $svcCount\n";
    echo "Total Pricing Variants Seeded: $priceCount\n";

} catch (Exception $e) {
    die("Seeding failed: " . $e->getMessage() . "\n");
}
