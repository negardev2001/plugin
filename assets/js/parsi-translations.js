/**
 * Parsi Shipping - JavaScript Translations (Persian/Farsi)
 */

var ParsiTranslations = {
    // Admin Orders
    'enabling': 'در حال فعال‌سازی...',
    'disabling': 'در حال غیرفعال‌سازی...',
    'error': 'خطا در ذخیره تنظیمات',
    'tracking': 'کد پیگیری:',
    'inactive': 'غیرفعال',
    
    // Tracking
    'checking': 'در حال بررسی...',
    'checkStatus': 'بررسی وضعیت رهگیری',
    'packageStatus': 'وضعیت بسته:',
    'status': 'وضعیت:',
    'currentLocation': 'مکان فعلی:',
    'timeline': 'تاریخچه:',
    'trackingError': 'خطا در دریافت اطلاعات رهگیری. لطفاً دوباره تلاش کنید.',
    'serverError': 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.',
    
    // Checkout/Cart
    'calculating': 'در حال محاسبه هزینه ارسال...',
    'rateError': 'خطا در محاسبه هزینه ارسال. لطفاً دوباره تلاش کنید.',
    'noRates': 'در حال حاضر روش ارسال در دسترس نیست.',
    'contactForQuote': 'ارسال (تماس برای استعلام قیمت)',
    'quoteError': 'در حال حاضر امکان محاسبه هزینه ارسال وجود ندارد. لطفاً برای استعلام قیمت با ما تماس بگیرید.',
    
    // API Errors
    'invalidCity': 'شهر وارد شده نامعتبر است. لطفاً آدرس ارسال خود را بررسی کنید.',
    'missingTariff': 'تعرفه ارسال برای این مسیر در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.',
    'authError': 'احراز هویت API ناموفق بود. لطفاً کلید API خود را بررسی کنید.',
    'connectionError': 'اتصال به سرویس ارسال ممکن نیست. لطفاً دوباره تلاش کنید.',
    'unknownError': 'خطایی در محاسبه هزینه ارسال رخ داد. لطفاً دوباره تلاش کنید.',
    'apiNotConfigured': 'تنظیمات API پیکربندی نشده است. لطفاً با مدیر فروشگاه تماس بگیرید.',
    'liveCalcDisabled': 'محاسبه زنده هزینه ارسال غیرفعال است.',
    'emptyDestination': 'مقصد ارسال مشخص نشده است.',
    'apiTimeout': 'زمان پاسخگویی API به پایان رسید. لطفاً دوباره تلاش کنید.',
    
    // Success Messages
    'settingsSaved': 'تنظیمات با موفقیت ذخیره شد.',
    'syncCompleted': 'همگام‌سازی وضعیت ارسال پارسی پست به پایان رسید.',
    'synced': 'موفق',
    'failed': 'ناموفق',
    
    // Labels
    'yes': 'بله',
    'no': 'خیر',
    'save': 'ذخیره',
    'cancel': 'انصراف',
    'confirm': 'تأیید',
    'loading': 'در حال بارگذاری...'
};

// Make translations available globally if needed
if (typeof window !== 'undefined') {
    window.ParsiTranslations = ParsiTranslations;
}
