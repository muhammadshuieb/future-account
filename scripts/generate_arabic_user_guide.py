#!/usr/bin/env python3
"""Generate Syna Co ERP Arabic user manual PDF (text-efficient, no screenshots)."""

from __future__ import annotations

import sys
from collections.abc import Callable
from pathlib import Path

from arabic_reshaper import reshape
from bidi.algorithm import get_display
from fpdf import FPDF

ROOT = Path(__file__).resolve().parents[1]
FONT_REG = ROOT / "docs" / "fonts" / "NotoNaskhArabic-Regular.ttf"
FONT_BOLD = ROOT / "docs" / "fonts" / "NotoNaskhArabic-Bold.ttf"
LOGO = ROOT / "docs" / "logo-on-light.png"
OUT = ROOT / "docs" / "SynaCo-User-Guide-AR.pdf"
OUT_AR = ROOT / "docs" / "دليل-استخدام-شركة-ساينا.pdf"

LIVE_URL = "https://synaacc.cloud"
DEMO_USER = "admin"
DEMO_PASS = "password"


def ar(text: str) -> str:
    if not text:
        return ""
    return get_display(reshape(text))


class ManualPDF(FPDF):
    def __init__(self) -> None:
        super().__init__(orientation="P", unit="mm", format="A4")
        self.set_auto_page_break(auto=True, margin=18)
        self.add_font("Noto", "", str(FONT_REG))
        self.add_font("Noto", "B", str(FONT_BOLD))
        self.toc_entries: list[tuple[str, int]] = []
        self._in_cover = False
        self._suppress_chrome = False

    def header(self) -> None:
        if self._in_cover or self._suppress_chrome or self.page_no() <= 2:
            return
        self.set_font("Noto", "", 8)
        self.set_text_color(90, 90, 90)
        self.cell(0, 6, ar("شركة ساينا — دليل استخدام النظام"), align="R", new_x="LMARGIN", new_y="NEXT")
        self.set_draw_color(200, 200, 200)
        self.line(self.l_margin, self.get_y(), self.w - self.r_margin, self.get_y())
        self.ln(4)
        self.set_text_color(0, 0, 0)

    def footer(self) -> None:
        if self._in_cover or self._suppress_chrome:
            return
        self.set_y(-14)
        self.set_font("Noto", "", 8)
        self.set_text_color(110, 110, 110)
        self.cell(0, 8, str(self.page_no()), align="C")
        self.set_text_color(0, 0, 0)

    def _block(self, text: str, h: float = 6.2, align: str = "R", fill: bool = False) -> None:
        self.set_x(self.l_margin)
        self.multi_cell(self.epw, h, ar(text), align=align, fill=fill)

    def chapter_title(self, num: str, title: str) -> None:
        self.add_page()
        label = f"{num}. {title}" if num else title
        self.toc_entries.append((label, self.page_no()))
        self.set_font("Noto", "B", 16)
        self.set_fill_color(15, 76, 92)
        self.set_text_color(255, 255, 255)
        self._block(label, h=11, fill=True)
        self.set_text_color(0, 0, 0)
        self.ln(4)

    def section(self, title: str) -> None:
        self.ln(2)
        self.set_font("Noto", "B", 12)
        self.set_text_color(15, 76, 92)
        self._block(title, h=7)
        self.set_text_color(0, 0, 0)
        self.ln(1)

    def p(self, text: str, size: int = 10) -> None:
        self.set_font("Noto", "", size)
        self._block(text)
        self.ln(1)

    def bullet(self, text: str) -> None:
        self.set_font("Noto", "", 10)
        self._block(f"- {text}")

    def step(self, n: int, text: str) -> None:
        self.set_font("Noto", "", 10)
        self._block(f"{n}) {text}")

    def note(self, text: str) -> None:
        self.set_fill_color(245, 248, 250)
        self.set_font("Noto", "", 9)
        self._block(f"ملاحظة: {text}", h=5.5, fill=True)
        self.ln(2)


def write_cover(pdf: ManualPDF) -> None:
    pdf._in_cover = True
    pdf.add_page()
    pdf.ln(28)
    if LOGO.exists():
        logo_y = pdf.get_y()
        pdf.image(str(LOGO), x=(210 - 42) / 2, y=logo_y, w=42)
        pdf.set_xy(pdf.l_margin, logo_y + 50)
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Noto", "B", 26)
    pdf._block("شركة ساينا", h=12, align="C")
    pdf.set_font("Noto", "B", 18)
    pdf.set_text_color(15, 76, 92)
    pdf._block("دليل استخدام نظام ERP", h=10, align="C")
    pdf.set_text_color(0, 0, 0)
    pdf.ln(4)
    pdf.set_font("Noto", "", 12)
    pdf._block("محاسبة • مخازن • مبيعات • مشتريات • موارد بشرية • تقارير", h=7, align="C")
    pdf.ln(10)
    pdf.set_font("Noto", "", 11)
    pdf._block(f"الموقع المباشر: {LIVE_URL}", h=7, align="C")
    pdf._block("التوقيت: آسيا/دمشق (Asia/Damascus)", h=7, align="C")
    pdf._block("اللغات: العربية (افتراضي) · English · Türkçe", h=7, align="C")
    pdf.ln(16)
    pdf.set_font("Noto", "", 10)
    pdf.set_text_color(80, 80, 80)
    pdf._block("هذا الدليل مبني على الشاشات والوظائف الفعلية في التطبيق.", h=6, align="C")
    pdf._block("نسخة نصية عملية بدون لقطات شاشة كبيرة.", h=6, align="C")
    pdf.set_text_color(0, 0, 0)
    pdf._in_cover = False


def write_toc(pdf: ManualPDF, entries: list[tuple[str, int]]) -> None:
    pdf.add_page()
    pdf.set_font("Noto", "B", 16)
    pdf.set_fill_color(15, 76, 92)
    pdf.set_text_color(255, 255, 255)
    pdf._block("جدول المحتويات", h=11, fill=True)
    pdf.set_text_color(0, 0, 0)
    pdf.ln(4)
    pdf.set_font("Noto", "", 10)
    for title, page in entries:
        pdf._block(f"{title}  ……  {page}", h=7)


def register_chapters() -> list[Callable[[ManualPDF], None]]:
    def ch_intro(p: ManualPDF) -> None:
        p.chapter_title("1", "مقدمة ونظرة عامة")
        p.p("نظام شركة ساينا (Syna Co) هو نظام ERP عربي باتجاه RTL لإدارة المحاسبة والمخزون والمبيعات والمشتريات والصناديق والموارد البشرية والتقارير.")
        p.section("ما يشمله النظام")
        for x in [
            "محاسبة: دليل حسابات وقيود مزدوجة",
            "مخازن: مستودعات، أصناف، أرصدة، حركات، تحويلات، جرد، تنبيهات، تتبع دفعات/تسلسلي",
            "مبيعات: عروض، أوامر، فواتير، مرتجعات، تحصيلات، فاتورة إلكترونية",
            "مشتريات: طلبات، أوامر، فواتير موردين، مرتجعات، مدفوعات",
            "شركاء: عملاء وموردون مع كشوف حساب وطباعة وواتساب",
            "نقدية: صناديق، بنوك، تحويلات، تسويات",
            "موارد بشرية: موظفون، حضور، إجازات، رواتب",
            "تقارير مالية وتشغيلية مع طباعة/PDF وواتساب",
            "إعدادات: ضريبة، لغة، عملات، نسخ احتياطي، واتساب، باركود، مستخدمون",
            "إدارة: شركات/فروع، سجل تدقيق، باركود وملصقات",
        ]:
            p.bullet(x)
        p.section("الدخول إلى النظام")
        p.bullet(f"الإنتاج: {LIVE_URL}")
        p.bullet("التطوير المحلي: الواجهة http://localhost:8080 — API على المنفذ 8000")
        p.note("حساب تجريبي عند التهيئة: اسم المستخدم admin وكلمة المرور password")

    def ch_login(p: ManualPDF) -> None:
        p.chapter_title("2", "تسجيل الدخول")
        p.p("صفحة الدخول تعرض شعار الشركة وحقول اسم المستخدم وكلمة المرور.")
        p.section("الخطوات")
        p.step(1, f"افتح الموقع {LIVE_URL} (أو عنوان التثبيت المحلي).")
        p.step(2, f"أدخل اسم المستخدم (مثال تجريبي: {DEMO_USER}).")
        p.step(3, f"أدخل كلمة المرور (مثال تجريبي: {DEMO_PASS}).")
        p.step(4, "اضغط زر الدخول.")
        p.step(5, "بعد النجاح تُفتح لوحة التحكم.")
        p.section("أخطاء شائعة")
        p.bullet("«بيانات الدخول غير صحيحة»: تحقق من اسم المستخدم/كلمة المرور أو حالة الحساب.")
        p.bullet("بعد استعادة نسخة احتياطية فارغة قد يختفي الأدمن — أعد تشغيل بذرة AdminUserSeeder من الخادم.")
        p.bullet("مشكلة جلسة/كوكي: تأكد أن نطاق الواجهة متوافق مع إعدادات Sanctum على الخادم.")

    def ch_dashboard(p: ManualPDF) -> None:
        p.chapter_title("3", "لوحة التحكم")
        p.p("الصفحة الرئيسية بعد الدخول. تعرض ملخصاً تشغيلياً بالعملة الأساسية.")
        p.section("المحتوى")
        p.bullet("رسم مبيعات يومية.")
        p.bullet("رسم مشتريات يومية.")
        p.bullet("تنبيهات: نقص مخزون، ذمم، قيود معلّقة — أو رسالة أنه لا توجد تنبيهات.")
        p.section("الاستخدام")
        p.step(1, "من القائمة الجانبية اختر «لوحة التحكم».")
        p.step(2, "راجع الاتجاه اليومي للمبيعات والمشتريات.")
        p.step(3, "إن ظهرت تنبيهات، انتقل للشاشة ذات الصلة (مخازن / قيود / شركاء) لمعالجتها.")
        p.note("يمكن تغيير لغة الواجهة من شريط التنقل (عربي / English / Türkçe).")

    def ch_accounts(p: ManualPDF) -> None:
        p.chapter_title("4", "المحاسبة — دليل الحسابات")
        p.p("شاشة «دليل الحسابات» تدير هيكلاً هرمياً بخمسة أنواع قيد.")
        p.section("أنواع الحسابات")
        for t in ["أصول", "خصوم", "ملكية", "إيرادات", "مصروفات"]:
            p.bullet(t)
        p.section("إضافة حساب")
        p.step(1, "افتح القائمة: المحاسبة - دليل الحسابات.")
        p.step(2, "اضغط إضافة / حساب جديد.")
        p.step(3, "أدخل الرمز والاسم والنوع والحساب الأب إن وُجد.")
        p.step(4, "حدد خيارات القابلية للترحيل والمجموعة حسب الحقول المتاحة.")
        p.step(5, "احفظ. يمكن النقر على صف لتعديله.")
        p.note("القيود تستخدم الحسابات القابلة للترحيل فقط.")

    def ch_journal(p: ManualPDF) -> None:
        p.chapter_title("5", "المحاسبة — القيود اليومية")
        p.p("قيد مزدوج: مجموع المدين يجب أن يساوي مجموع الدائن قبل الترحيل.")
        p.section("إنشاء قيد")
        p.step(1, "افتح «القيود اليومية».")
        p.step(2, "أضف قيداً جديداً: التاريخ والبيان.")
        p.step(3, "أضف سطور التفصيل: حساب + مدين أو دائن + مذكرة اختيارية.")
        p.step(4, "راجع الإجمالي أسفل النموذج (مدين | دائن) حتى يتوازن.")
        p.step(5, "احفظ كمسودة، أو رحّل مباشرة إن كان متوازناً.")
        p.step(6, "للمسودات: من القائمة يمكن ترحيل القيد لاحقاً.")
        p.section("الحالات")
        p.bullet("مسودة: قابلة للتعديل.")
        p.bullet("مرحّل: للعرض عادةً.")
        p.note("لا يمكن حذف مستند مرحّل.")

    def ch_sales(p: ManualPDF) -> None:
        p.chapter_title("6", "المبيعات")
        p.p("تبويبات الشاشة: عروض الأسعار، أوامر البيع، الفواتير، المرتجعات، التحصيلات.")
        p.section("المسار الشائع: عرض - أمر - فاتورة")
        p.step(1, "من «المبيعات» افتح تبويب عروض الأسعار - عرض سعر جديد.")
        p.step(2, "اختر العميل والعملة وسعر الصرف عند الحاجة، وأضف الأصناف والكميات والأسعار.")
        p.step(3, "احفظ العرض. يمكن تحويله لأمر بيع ثم لفاتورة من أزرار التحويل.")
        p.step(4, "أو أنشئ فاتورة مبيعات مباشرة من تبويب الفواتير.")
        p.step(5, "عند الترحيل يُحدَّث المخزون والذمم حسب إعدادات المستند.")
        p.section("الفاتورة والسطور")
        p.bullet("اختيار المستودع وموقع المخزون.")
        p.bullet("مسح باركود (قارئ USB) لإضافة الصنف تلقائياً.")
        p.bullet("إن كان الصنف يتتبع دفعات: يظهر المتبقي حسب الدفعة ويمكن اختيار «استخدام».")
        p.bullet("الضريبة تظهر وفق إعداد تفعيل الضريبة ونسبتها في الإعدادات.")
        p.bullet("حد الائتمان: قد تظهر رسالة تجاوز حد الائتمان للعميل.")
        p.section("مرتجعات وتحصيلات")
        p.step(1, "مرتجع مبيعات: اختر البيانات والأصناف ثم رحّل.")
        p.step(2, "تحصيل (سند قبض): سجّل المبلغ والصندوق المرتبط بالعميل.")
        p.section("طباعة وواتساب وفاتورة إلكترونية")
        p.bullet("من الفاتورة: طباعة تفتح صفحة طباعة مخصصة.")
        p.bullet("زر واتساب: تجهيز PDF أو صورة وإرسال (يدوي عبر wa.me أو Cloud API إن رُبط).")
        p.bullet("خيار فاتورة إلكترونية متاح ضمن سير المبيعات في الواجهة.")

    def ch_purchases(p: ManualPDF) -> None:
        p.chapter_title("7", "المشتريات")
        p.p("التبويبات: طلبات الشراء، أوامر الشراء، فواتير الموردين، مرتجعات المشتريات، المدفوعات.")
        p.section("المسار: طلب - أمر - فاتورة")
        p.step(1, "أنشئ طلب شراء بالأصناف والكميات.")
        p.step(2, "حوّله لأمر شراء عند الاعتماد.")
        p.step(3, "حوّل الأمر لفاتورة مورد، أو أنشئ فاتورة مباشرة.")
        p.step(4, "حدد المورد والمستودع والعملة والتكلفة والضريبة إن لزم.")
        p.step(5, "للأصناف ذات الدفعات/التسلسلي: أدخل رقم الدفعة أو الرقم التسلسلي عند الاستلام.")
        p.step(6, "رحّل الفاتورة لتحديث المخزون والتزام المورد.")
        p.section("مرتجعات ومدفوعات")
        p.bullet("مرتجع مشتريات: إرجاع أصناف للمورد مع الترحيل.")
        p.bullet("سند صرف للمورد: اختر المورد والمبلغ والصندوق ثم رحّل.")
        p.bullet("طباعة فاتورة المشتريات وواتساب متاحان من شاشة الفاتورة.")

    def ch_warehouse(p: ManualPDF) -> None:
        p.chapter_title("8", "المخازن والمخزون (بما فيها الدفعات)")
        p.p("التبويبات: مستودعات، أصناف، تصنيفات، وحدات قياس، أرصدة، حركات، تحويلات، تنبيهات، الجرد.")
        p.section("إعداد أساسي")
        p.step(1, "أضف مستودعاً من تبويب المستودعات.")
        p.step(2, "عرّف التصنيفات ووحدات القياس.")
        p.step(3, "أضف صنفاً: الاسم، أسعار التكلفة/البيع، باركود، حد إعادة الطلب.")
        p.step(4, "فعّل «تتبع الدفعات» و/أو «تتبع التسلسلي» على الصنف عند الحاجة.")
        p.section("الأرصدة والحركات والتحويلات")
        p.bullet("الأرصدة: عرض الكميات حسب المستودع (وقد تُعرض حسب الدفعة عند التتبع).")
        p.bullet("الحركات: إدخال/إخراج يدوي مع كمية ودفعة/تسلسلي عند اللزوم.")
        p.bullet("التحويلات: من مستودع إلى آخر لنفس الصنف مع دعم الدفعة/التسلسلي.")
        p.bullet("التنبيهات: أصناف تحت حد إعادة الطلب.")
        p.section("الجرد")
        p.step(1, "تبويب الجرد - جرد جديد: تاريخ، صنف، مستودع، الكمية المعدودة، دفعة/تسلسلي إن لزم.")
        p.step(2, "احفظ ثم «ترحيل الفروقات» لتعديل الرصيد.")
        p.section("الدفعات (Batch) عملياً")
        p.bullet("عند الشراء: أدخل رقم الدفعة على سطر الفاتورة.")
        p.bullet("عند البيع: النظام يعرض المتبقي حسب الدفعة ويمكن اختيار دفعة (مساعدة FIFO في الواجهة).")
        p.bullet("الجرد والتحويلات يقبلان رقم الدفعة للأصناف المتتبَّعة.")
        p.note("الكميات تُدخل بعدد الوحدات (وليس بالآلاف) كما توضّح تلميحات الحقول.")

    def ch_partners(p: ManualPDF) -> None:
        p.chapter_title("9", "العملاء والموردون وكشوف الحساب")
        p.p("تبويبان: العملاء والموردون. الحقول الأساسية: الرمز، الاسم، الهاتف، حد الائتمان، الحالة.")
        p.section("إضافة شريك")
        p.step(1, "افتح «العملاء والموردون».")
        p.step(2, "اختر التبويب ثم إضافة.")
        p.step(3, "أدخل البيانات واحفظ. النقر على الصف يعدّل السجل.")
        p.section("كشف الحساب")
        p.step(1, "اختر عميلاً أو مورداً لعرض الكشف.")
        p.step(2, "حدد فترة من/إلى إن رغبت.")
        p.step(3, "راجع الرصيد الافتتاحي والختامي والحركات.")
        p.step(4, "طباعة: تفتح صفحة طباعة مخصصة لكشف العميل أو المورد.")
        p.step(5, "واتساب: أرسل الكشف كملف (PDF/صورة) مع رقم الجوال.")

    def ch_cash(p: ManualPDF) -> None:
        p.chapter_title("10", "الصناديق والبنوك")
        p.p("التبويبات: الصناديق، البنوك، التحويلات، التسويات.")
        p.section("الصناديق والبنوك")
        p.step(1, "أضف صندوقاً نقدياً من تبويب الصناديق.")
        p.step(2, "أضف حساباً بنكياً من تبويب البنوك.")
        p.section("التحويلات")
        p.step(1, "تبويب التحويلات - تحويل جديد.")
        p.step(2, "حدد النوع من/إلى (صندوق أو بنك) والمصدر والوجهة والمبلغ والتاريخ.")
        p.step(3, "احفظ حسب الحالة المعروضة في الواجهة.")
        p.section("التسويات")
        p.bullet("أنشئ تسوية كشف حساب بنكي لمطابقة الرصيد مع كشف البنك.")

    def ch_hr(p: ManualPDF) -> None:
        p.chapter_title("11", "الموارد البشرية")
        p.p("التبويبات: الموظفون، الحضور، الإجازات، الرواتب.")
        p.section("الموظفون")
        p.step(1, "أضف موظفاً: رقم وظيفي، الاسم، المسمى، الراتب الأساسي.")
        p.section("الحضور")
        p.step(1, "سجّل حضوراً: الموظف، التاريخ، الحالة، وقت الدخول والخروج.")
        p.section("الإجازات")
        p.step(1, "اطلب إجازة: الموظف، من/إلى، نوع الإجازة، السبب — الحالة تبدأ قيد الانتظار.")
        p.section("الرواتب")
        p.step(1, "أنشئ سجل راتب: الموظف، الفترة (شهر)، بدلات، استقطاعات، ثم الترحيل حسب الحالة.")

    def ch_reports(p: ManualPDF) -> None:
        p.chapter_title("12", "التقارير والطباعة وواتساب")
        p.p("شاشة التقارير تعرض المبالغ بالعملة الأساسية وتدعم الطباعة وإرسال واتساب.")
        p.section("قائمة التقارير المتاحة")
        for r in [
            "ميزان المراجعة",
            "دفتر الأستاذ العام (باختيار حساب)",
            "قائمة الدخل (الأرباح والخسائر)",
            "الميزانية العمومية",
            "قائمة التدفقات النقدية",
            "تقرير المبيعات",
            "تقرير المشتريات",
            "تقرير المخزون",
            "تقرير مجمل الربح",
            "تقرير الضريبة",
            "كشف حساب عميل",
            "كشف حساب مورد",
            "حركة صنف",
        ]:
            p.bullet(r)
        p.section("خطوات الاستخدام")
        p.step(1, "افتح «التقارير» واختر نوع التقرير من التبويبات.")
        p.step(2, "حدد الفترة (من/إلى) والفرع عند توفره، أو الحساب/العميل/المورد/الصنف حسب التقرير.")
        p.step(3, "انتظر تحميل النتائج.")
        p.step(4, "طباعة / PDF: يخفي الشريط الجانبي عبر أنماط الطباعة؛ احفظ PDF من مربع حوار المتصفح.")
        p.step(5, "كشوف العملاء/الموردين تفتح نافذة طباعة مخصصة.")
        p.step(6, "واتساب: بجانب الطباعة اختر الصيغة (PDF أو صورة PNG) وعدّل رقم الجوال ثم أرسل.")
        p.note("بدون WhatsApp Cloud API: يُنزَّل الملف ويُفتح واتساب — أرفق الملف يدوياً من التنزيلات. مع الربط: محاولة إرسال تلقائي.")

    def ch_settings(p: ManualPDF) -> None:
        p.chapter_title("13", "الإعدادات (ضريبة، لغة، نسخ، واتساب)")
        p.p("تبويبات الإعدادات: عام، العملات وأسعار الصرف، النسخ الاحتياطي، واتساب، قارئ الباركود، المستخدمون والصلاحيات.")
        p.section("عام — الشركة والضريبة واللغة")
        p.step(1, "افتح الإعدادات - عام.")
        p.step(2, "حدّث بيانات الشركة الظاهرة في القسم.")
        p.step(3, "فعّل أو عطّل الضريبة. عند التعطيل تُخفى من الفواتير ويُحسب المبلغ بدون ضريبة.")
        p.step(4, "اضبط نسبة الضريبة %.")
        p.step(5, "اختر اللغة الافتراضية (تُستخدم عند أول دخول إن لم يختر المستخدم لغة).")
        p.step(6, "احفظ الإعدادات.")
        p.section("النسخ الاحتياطي (مدير فقط)")
        p.step(1, "تبويب النسخ الاحتياطي.")
        p.step(2, "إنشاء نسخة الآن (PostgreSQL عبر pg_dump).")
        p.step(3, "تنزيل ملف .dump عند الحاجة.")
        p.step(4, "استعادة: تتطلب تأكيداً وتستبدل البيانات الحالية.")
        p.step(5, "جدولة نسختين يومياً بأوقات قابلة للضبط (يتطلب تشغيل جدولة Laravel على الخادم).")
        p.bullet("وجهات إضافية (Google Drive / Telegram) تُضبط عبر متغيرات البيئة على الخادم.")
        p.section("واتساب")
        p.bullet("عرض حالة الربط: مربوط Cloud API أو غير مربوط (wa.me + تنزيل).")
        p.bullet("على الخادم: WHATSAPP_TOKEN و WHATSAPP_PHONE_NUMBER_ID من Meta Business.")
        p.section("قارئ الباركود (إرشادات داخل الإعدادات)")
        p.step(1, "وصّل قارئ USB (يعمل كلوحة مفاتيح).")
        p.step(2, "افتح المبيعات أو المخزن أو الباركود وانقر حقل المسح.")
        p.step(3, "امسح الملصق — يُضاف أو يُحدَّد الصنف.")
        p.step(4, "تأكد أن لكل صنف باركود في بطاقته.")

    def ch_users(p: ManualPDF) -> None:
        p.chapter_title("14", "المستخدمون والأدوار والصلاحيات")
        p.p("متاح لمن لديه صلاحية إدارة المستخدمين أو دور مدير النظام — من الإعدادات - المستخدمون.")
        p.section("الأدوار المعرفة")
        for r in [
            "مدير النظام (admin)",
            "محاسب (accountant)",
            "أمين المستودع (warehouse)",
            "مبيعات (sales)",
            "مشتريات (purchasing)",
        ]:
            p.bullet(r)
        p.section("إدارة مستخدم")
        p.step(1, "مستخدم جديد: الاسم الأول، اسم العائلة، اسم المستخدم، الجوال، البريد (اختياري)، كلمة المرور، الأدوار.")
        p.step(2, "عند التعديل: اترك كلمة المرور فارغة للإبقاء على الحالية.")
        p.step(3, "عدّل صلاحيات الأدوار من قسم الأدوار — صلاحيات مدير النظام ثابتة ولا تُعدَّل.")
        p.section("أمثلة صلاحيات")
        p.bullet("عرض/إدارة الحسابات والقيود وترحيل القيود")
        p.bullet("عرض/إدارة المبيعات والمشتريات والمخزن والعملاء والموردين والصناديق والموارد البشرية")
        p.bullet("عرض التقارير — إدارة الإعدادات — إدارة المستخدمين")
        p.note("النسخ الاحتياطي للمدير فقط.")

    def ch_currencies(p: ManualPDF) -> None:
        p.chapter_title("15", "العملات وأسعار الصرف")
        p.p("العملات المدعومة: SYP (أساسية افتراضياً) و TRY و USD.")
        p.section("الخطوات")
        p.step(1, "الإعدادات - العملات وأسعار الصرف.")
        p.step(2, "راجع العملات المدعومة والعملة الأساسية.")
        p.step(3, "أدخل سعر صرف: من عملة - إلى عملة - السعر - حفظ.")
        p.step(4, "المعنى: وحدة واحدة من المصدر = السعر × عملة الهدف.")
        p.section("أثر العملات على المستندات")
        p.bullet("الفواتير تخزّن العملة وسعر الصرف والمبلغ الأساسي.")
        p.bullet("القيود المحاسبية تُرحَّل بالمبلغ الأساسي.")
        p.bullet("التقارير تعرض المبالغ بالعملة الأساسية.")

    def ch_barcode(p: ManualPDF) -> None:
        p.chapter_title("16", "الباركود والملصقات")
        p.section("توليد وطباعة ملصقات")
        p.step(1, "من القائمة: «الباركود والملصقات».")
        p.step(2, "اختر الأصناف - تجهيز ملصقات - طباعة الملصقات.")
        p.step(3, "أو من شاشة المخازن: زر توليد لصنف بلا باركود.")
        p.bullet("توليد أنماط مثل Code128/EAN حسب تنفيذ الواجهة والـ API.")
        p.section("في العمليات")
        p.bullet("المبيعات والمخزن: حقل مسح باركود لإضافة/تحديد الصنف.")

    def ch_companies(p: ManualPDF) -> None:
        p.chapter_title("17", "الشركات والفروع")
        p.p("إدارة الكيانات القانونية والفروع التشغيلية — تبويبا الشركات والفروع.")
        p.section("شركة")
        p.step(1, "شركة جديدة: الرمز، الاسم عربي، الاسم إنجليزي، الرقم الضريبي.")
        p.section("فرع")
        p.step(1, "فرع جديد: الشركة، اسم الفرع، المدينة، وهل هو فرع رئيسي.")
        p.note("بعض التقارير (مثل المبيعات/المشتريات) تدعم تصفية حسب الفرع عند توفره.")

    def ch_audit(p: ManualPDF) -> None:
        p.chapter_title("18", "سجل التدقيق")
        p.p("تتبع العمليات والتغييرات: المستخدم، الإجراء، الكيان، وعنوان IP.")
        p.section("أنواع الإجراءات الشائعة")
        for a in ["إنشاء", "تعديل", "حذف", "ترحيل", "إلغاء", "حركة مخزون"]:
            p.bullet(a)
        p.step(1, "افتح «سجل التدقيق» من مجموعة الإدارة.")
        p.step(2, "راجع السجلات زمنياً لمعرفة من نفّذ ماذا.")
        p.note("إن كانت القائمة فارغة: ستظهر التعديلات الجديدة بعد بدء الاستخدام.")

    def ch_errors(p: ManualPDF) -> None:
        p.chapter_title("19", "أخطاء ومشاكل شائعة")
        p.section("دخول وصلاحيات")
        p.bullet("فشل الدخول بعد استعادة نسخة فارغة - أعد إنشاء مستخدم الأدمن عبر البذرة.")
        p.bullet("تعذر تحميل الصناديق/البنوك - تحقق من صلاحية الصناديق والبنوك.")
        p.bullet("قائمة النسخ الاحتياطي لا تُحمَّل - يتطلب حساب مدير.")
        p.section("مستندات ومخزون")
        p.bullet("لا يمكن حذف مستند مرحّل أو محوّل.")
        p.bullet("القيد غير متوازن - صحّح المدين/الدائن قبل الترحيل.")
        p.bullet("لا يوجد رصيد في المستودع / باركود غير موجود - راجع الأرصدة وبطاقة الصنف.")
        p.bullet("تجاوز حد الائتمان - ارفع الحد من بطاقة العميل أو قلّل قيمة الفاتورة.")
        p.bullet("مطلوب سعر صرف عند اختيار عملة غير الأساسية.")
        p.section("طباعة وواتساب")
        p.bullet("رقم جوال غير صالح - استخدم صيغة سورية 09… (تُحوَّل إلى +963) أو رقم دولي صحيح.")
        p.bullet("فشل تجهيز الملف - أعد المحاولة وتأكد من ظهور محتوى منطقة الطباعة.")
        p.section("نسخ احتياطي")
        p.bullet("الاستعادة تستبدل كل البيانات — خذ نسخة حديثة قبل الاستعادة.")
        p.bullet("Google Drive غير مربوط - النسخ تُحفظ محلياً فقط حتى ضبط بيانات الاعتماد على الخادم.")

    def ch_nav(p: ManualPDF) -> None:
        p.chapter_title("20", "خريطة القائمة والإشعارات")
        p.section("مجموعات القائمة الجانبية")
        p.bullet("الرئيسية: لوحة التحكم")
        p.bullet("المحاسبة: دليل الحسابات، القيود اليومية، الإعدادات")
        p.bullet("العمليات: المبيعات، المشتريات، المخازن، العملاء والموردون، الصناديق والبنوك، الموارد البشرية، التقارير، الباركود والملصقات")
        p.bullet("الإدارة: سجل التدقيق، الشركات والفروع")
        p.section("الإشعارات")
        p.bullet("أيقونة الإشعارات في الشريط: عرض القائمة، تعليم الكل كمقروء، أو رسالة عدم وجود إشعارات.")
        p.section("تسجيل الخروج")
        p.bullet("من القائمة: تسجيل الخروج لإنهاء الجلسة.")

    return [
        ch_intro,
        ch_login,
        ch_dashboard,
        ch_accounts,
        ch_journal,
        ch_sales,
        ch_purchases,
        ch_warehouse,
        ch_partners,
        ch_cash,
        ch_hr,
        ch_reports,
        ch_settings,
        ch_users,
        ch_currencies,
        ch_barcode,
        ch_companies,
        ch_audit,
        ch_errors,
        ch_nav,
    ]


def build_pass(*, include_toc: bool, toc_entries: list[tuple[str, int]] | None) -> tuple[ManualPDF, list[tuple[str, int]]]:
    pdf = ManualPDF()
    pdf.set_margins(16, 16, 16)
    write_cover(pdf)
    if include_toc:
        write_toc(pdf, toc_entries or [])
    for writer in register_chapters():
        writer(pdf)
    return pdf, list(pdf.toc_entries)


def build() -> Path:
    if not FONT_REG.exists() or not FONT_BOLD.exists():
        print("Missing Arabic fonts under docs/fonts/", file=sys.stderr)
        sys.exit(1)

    # Pass 1: no TOC page - chapters start at page 2
    _, raw_toc = build_pass(include_toc=False, toc_entries=None)
    # Pass 2 inserts TOC as page 2 - shift chapter pages by +1
    shifted = [(title, page + 1) for title, page in raw_toc]

    pdf, _ = build_pass(include_toc=True, toc_entries=shifted)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    pdf.output(str(OUT))
    try:
        OUT_AR.write_bytes(OUT.read_bytes())
    except OSError as exc:
        print(f"Arabic filename copy skipped: {exc}")

    print(f"Wrote {OUT}")
    print(f"Pages: {pdf.page_no()}")
    print(f"Size bytes: {OUT.stat().st_size}")
    return OUT


if __name__ == "__main__":
    build()
