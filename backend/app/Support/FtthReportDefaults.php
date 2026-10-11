<?php

namespace App\Support;

class FtthReportDefaults
{
    /** @return list<array{type: string, location: string, unit: string, planned: float, actual: float, notes: string}> */
    public static function works(): array
    {
        return [
            ['type' => 'الحفر', 'location' => '', 'unit' => 'متر', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'تمديد الدكت', 'location' => '', 'unit' => 'متر', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'تركيب المنهولات', 'location' => '', 'unit' => 'عدد', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'تجهيز / تسوية قاع الخندق', 'location' => '', 'unit' => 'متر', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'الرمل التحتي / الحماية', 'location' => '', 'unit' => 'متر', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'الردم والدمك', 'location' => '', 'unit' => 'متر', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'إعادة الإسفلت / البلاط', 'location' => '', 'unit' => 'متر مربع', 'planned' => 0, 'actual' => 0, 'notes' => ''],
            ['type' => 'أعمال أخرى', 'location' => '', 'unit' => '', 'planned' => 0, 'actual' => 0, 'notes' => ''],
        ];
    }

    /** @return list<array{item: string, unit: string, opening: float, received: float, used: float, notes: string}> */
    public static function materials(): array
    {
        return [
            ['item' => 'دكت / مواسير HDPE', 'unit' => 'متر', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'منهولات', 'unit' => 'عدد', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'وصلات الدكت', 'unit' => 'عدد', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'رمل', 'unit' => 'م³', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'مواد ردم', 'unit' => 'م³', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'حفارة / JCB', 'unit' => 'عدد', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'معدات دمك', 'unit' => 'عدد', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
            ['item' => 'حواجز ومعدات سلامة', 'unit' => 'عدد', 'opening' => 0, 'received' => 0, 'used' => 0, 'notes' => ''],
        ];
    }

    /** @return list<array{activity: string, location: string, unit: string, target: float, team: string, resources: string, notes: string}> */
    public static function nextDayPlan(): array
    {
        return [
            ['activity' => 'الحفر', 'location' => '', 'unit' => 'متر', 'target' => 0, 'team' => 'فريق الحفر', 'resources' => 'حفارة، شاحنة، حواجز', 'notes' => ''],
            ['activity' => 'تمديد الدكت', 'location' => '', 'unit' => 'متر', 'target' => 0, 'team' => 'فريق الدكت', 'resources' => 'دكت، وصلات، حبل سحب', 'notes' => ''],
            ['activity' => 'تركيب المنهولات', 'location' => '', 'unit' => 'عدد', 'target' => 0, 'team' => 'فريق التركيب', 'resources' => 'منهولات، معدات رفع، ميزان', 'notes' => ''],
            ['activity' => 'تسوية القاع وفحص المسار', 'location' => '', 'unit' => 'متر', 'target' => 0, 'team' => 'المساح / المشرف', 'resources' => 'أدوات قياس المناسيب', 'notes' => ''],
            ['activity' => 'الردم والدمك', 'location' => '', 'unit' => 'متر', 'target' => 0, 'team' => 'فريق الردم', 'resources' => 'مواد ردم، مدحلة / دكاك', 'notes' => 'بعد الفحص والموافقة'],
            ['activity' => 'إعادة الوضع / تنظيف الموقع', 'location' => '', 'unit' => 'متر مربع', 'target' => 0, 'team' => 'فريق الموقع', 'resources' => 'مواد إعادة الوضع', 'notes' => ''],
            ['activity' => 'أعمال أخرى', 'location' => '', 'unit' => '', 'target' => 0, 'team' => '', 'resources' => '', 'notes' => ''],
        ];
    }

    /** @return list<array{item: string, quantity: string, unit: string, location: string, needed_at: string, owner: string, notes: string}> */
    public static function nextDayResources(): array
    {
        return [
            ['item' => 'عمال / فنيون', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'مشرف / مسّاح', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'حفارة JCB', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'شاحنة نقل ناتج الحفر', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'دكت ومستلزماته', 'quantity' => '', 'unit' => '', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'منهولات وأغطية', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'معدات دمك', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
            ['item' => 'حواجز وإشارات سلامة', 'quantity' => '', 'unit' => 'عدد', 'location' => '', 'needed_at' => '', 'owner' => '', 'notes' => ''],
        ];
    }
}
