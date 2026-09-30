/* Immutable question definitions and conditional question construction. */

const useLabels = { commute: 'رفت‌وآمد', music: 'موسیقی', work: 'کار و تماس', gaming: 'بازی', gift: 'هدیه' };

/* ANC only runs while the product is used wirelessly: plug a cable into a
 * hybrid model and the noise-cancelling circuit switches off. Asking for ANC
 * and then offering a wired connection promises something the catalog cannot
 * deliver, so the wired option is withheld for that priority. */
const wantsAnc = ( answers ) => answers.pain === 'noise';

/** Connection options for the flow; wired is withheld when ANC is required. */
const connectionOptions = ( answers ) => {
	const headphones = answers.flow === 'headphones';
	const wireless = [ 'wireless', 'بی‌سیم با بلوتوث', headphones ? 'گوشی یا لپ‌تاپ' : 'آزاد از کابل' ];
	const wired = headphones
		? [ 'aux', 'با کابل AUX', 'برای دستگاه با جک صدا' ]
		: [ 'usbc', 'سیمی USB-C', 'بدون شارژ باتری' ];
	return wantsAnc( answers ) ? [ wireless ] : [ wireless, wired ];
};

/** Build the ordered question list for the current answers. */
const questions = ( answers = {} ) => {
	const list = [
		{ key: 'use', title: 'بیشتر کجای روزت همراهت است؟', help: 'کاربردی را انتخاب کن که بیشترین وقتت را می‌گیرد.', scene: 'music', sceneTitle: 'از روزمره‌ات شروع کنیم.', sceneCopy: 'لازم نیست مشخصات فنی را از حفظ باشی.', options: [
			[ 'commute', 'توی مسیر', 'مترو، اتوبوس و رفت‌وآمد' ],
			[ 'music', 'وقت موسیقی', 'خانه، استراحت و پلی‌لیست' ],
			[ 'work', 'کار و تماس', 'جلسه و صحبت روزانه' ],
			[ 'gaming', 'وقت بازی', 'اتصال و تأخیر مهم است' ],
			[ 'gift', 'برای هدیه', 'انتخاب برای یک نفر دیگر' ],
		] },
		{ key: 'pain', title: answers.use === 'gift' ? 'کدام نیازش را بهتر می‌شناسی؟' : 'از تجربه قبلی، چه چیزی را نمی‌خواهی تکرار کنی؟', help: 'مهم‌ترین مورد را بگو تا اولویت پیشنهاد روشن شود.', scene: answers.use || 'balanced', sceneTitle: 'دقیقاً همان چیزی که مهم است.', sceneCopy: 'هر پاسخ یا شرط انتخاب را عوض می‌کند یا نکته مهمش را روشن می‌کند؛ اگر رتبه‌بندی تغییر نکند، همان را می‌گوییم.', options: [
			[ 'noise', 'صدای محیط مزاحم است', 'می‌خواهم ANC داشته باشد' ],
			[ 'fit', 'در گوشم راحت نیست', 'فرم و راحتی مهم‌تر است' ],
			[ 'calls', 'تماس اذیتم می‌کند', 'صدای من واضح‌تر برسد' ],
			[ 'switch', 'مدام اتصال را عوض می‌کنم', 'دو دستگاه هم‌زمان می‌خواهم' ],
			[ 'charge', 'شارژکردن خسته‌ام کرده', 'کمتر درگیر شارژ شوم' ],
			[ 'balanced', 'مشکل خاصی ندارم', 'یک انتخاب متعادل می‌خواهم' ],
		] },
		{ key: 'connection', title: 'چطور می‌خواهی وصلش کنی؟', help: wantsAnc( answers ) ? 'حذف نویز فعال (ANC) فقط در حالت بی‌سیم کار می‌کند؛ روی کابل مدار ANC خاموش است. پس فقط اتصال بی‌سیم را می‌بینی. اگر کابل می‌خواهی، اولویت را عوض کن.' : ( answers.pain === 'charge' ? 'مدل سیمی به باتری نیاز ندارد؛ شارژدهی مدل‌های بی‌سیم با عدد قابل‌مقایسه رتبه‌بندی نمی‌شود.' : 'اتصالی را انتخاب کن که واقعاً استفاده می‌کنی.' ), scene: 'wireless', sceneTitle: 'بدون دردسرِ اتصال.', sceneCopy: wantsAnc( answers ) ? 'ANC فقط وقتی بی‌سیم کار می‌کند معنا دارد؛ مدل سیمی از این مسیر کنار می‌رود.' : 'نوع اتصال، شرط انتخاب است؛ مدل ناسازگار کنار می‌رود.', options: connectionOptions( answers ) },
	];
	if ( answers.connection === 'usbc' ) {
		list.push( { key: 'device', title: 'درگاه دستگاهت کدام است؟', help: 'اگر مدل دقیق دستگاه را نمی‌دانی، حدس نمی‌زنیم.', scene: 'usbc', sceneTitle: 'USB-C فقط شکل درگاه نیست.', sceneCopy: 'پشتیبانی از صدای USB باید برای مدل دستگاه بررسی شود.', options: [
			[ 'usbc', 'USB-C دارد', 'سازگاری صوتی را بررسی می‌کنم' ],
			[ 'lightning', 'آیفون با Lightning', 'اتصال مستقیم USB-C ندارم' ],
			[ 'unknown', 'مطمئن نیستم', 'اول دستگاه را بررسی می‌کنم' ],
		] } );
	}
	if ( answers.use === 'work' || answers.pain === 'calls' ) {
		list.push( { key: 'calls', title: 'تماس‌هایت معمولاً کجا هستند؟', help: 'ANC به‌تنهایی کیفیت میکروفون را ثابت نمی‌کند.', scene: 'calls', sceneTitle: 'صدای تو، آن طرف تماس.', sceneCopy: 'برای محیط شلوغ، وجود میکروفون خوب باید با تست ثابت شود.', options: [
			[ 'quiet', 'اتاق یا محل نسبتاً آرام', 'تماس و جلسه معمول' ],
			[ 'noisy', 'خیابان یا محیط شلوغ', 'نمونه صدای میکروفون لازم دارم' ],
		] } );
	}
	if ( answers.flow === 'earbuds' ) {
		list.push( { key: 'fit', title: 'با کدام فرم داخل گوش راحت‌تری؟', help: 'این پاسخ فرم محصول را محدود می‌کند؛ راحتی نهایی شخصی است.', scene: 'fit', sceneTitle: 'راحتی، جزئیات کوچکی نیست.', sceneCopy: 'اندازه گوش و سری می‌تواند تجربه استفاده را تغییر دهد.', options: [
			[ 'silicone', 'با سری سیلیکونی', 'سری قابل تعویض' ],
			[ 'open', 'بدون سری سیلیکونی', 'با سری داخل گوش راحت نیستم' ],
			[ 'any', 'تفاوتی ندارد', 'هر دو فرم را بررسی کن' ],
		] } );
	} else if ( answers.pain === 'fit' ) {
		list.push( { key: 'fit', title: 'چه چیزی بیشتر روی راحتی‌ات اثر دارد؟', help: 'فشار پد و گرمای گوش را با یک عدد فنی نمی‌شود تضمین کرد.', scene: 'open', sceneTitle: 'برای ساعت‌های طولانی.', sceneCopy: 'در نتیجه، نکته مربوط به راحتی را هم کنار پیشنهاد می‌بینی.', options: [
			[ 'long', 'استفاده طولانی', 'گرما و فشار پد مهم است' ],
			[ 'glasses', 'عینک می‌زنم', 'فشار روی دسته عینک مهم است' ],
			[ 'any', 'تفاوتی ندارد', 'محدودیت خاصی ندارم' ],
		] } );
	}
	list.push( { key: 'budget', title: 'تا چه مبلغی راحت خرید می‌کنی؟', help: 'بودجه‌ات سقف انتخاب است. افزایش آن فقط با انتخاب خودت انجام می‌شود.', scene: 'budget', sceneTitle: 'ارزش خرید برای نیاز تو.', sceneCopy: 'هزینه بیشتر باید یک قابلیت موردنیاز اضافه کند.' } );
	return list;
};

/**
 * Per-answer feedback: the exact effect of the answer, in the shopper's words.
 *
 * Every entry names what the answer does — فیلتر (eligibility), امتیاز
 * (ranking), or فقط توضیح (copy/notice only). Answers the store has no
 * comparable data for (call quality, battery, comfort) are explanation-only
 * and say so, so the interface never promises a ranking change it does not
 * make. The full contract lives in RecommendationEngine::answer_effects().
 */
const feedback = {
	/* use */
	commute: 'امتیاز: ANC در مسیر رفت‌وآمد ۱۲ امتیاز می‌گیرد (فقط در اتصال بی‌سیم).',
	music: 'بدون فیلتر و بدون امتیاز: اتصال و بودجه انتخاب را محدود می‌کنند.',
	work: 'فیلتر+امتیاز: در مسیر AUX میکروفون شرط است و اتصال هم‌زمان ۱۰ امتیاز می‌گیرد.',
	gaming: 'فیلتر: بازی با اتصال بی‌سیم پیشنهاد نمی‌شود چون تأخیر آزموده‌شده نداریم.',
	gift: 'بدون فیلتر و بدون امتیاز: بودجه، اتصال و مشخصات ثبت‌شده مبنا هستند.',
	/* pain */
	noise: 'فیلتر+امتیاز: فقط مدل‌های با ANC برای شنیدن (و فقط در اتصال بی‌سیم) می‌مانند و ۲۵ امتیاز می‌گیرند.',
	switch: 'فیلتر+امتیاز: اتصال هم‌زمان دو دستگاه شرط انتخاب است و ۲۵ امتیاز می‌گیرد.',
	calls: 'فقط توضیح: عدد قابل‌مقایسه‌ای برای کیفیت میکروفون ثبت نشده؛ نکته تماس را نشان می‌دهیم و رتبه‌بندی را تغییر نمی‌دهیم.',
	fit: 'فیلتر: فرم سری (سیلیکونی/باز) شرط انتخاب می‌شود؛ راحتی نهایی فقط توضیح است.',
	charge: 'فقط توضیح: شارژدهی این مدل‌ها قابل مقایسه نیست؛ رتبه‌بندی را با عددهای غیرقابل مقایسه تغییر نمی‌دهیم و مسیر سیمی را هم بررسی می‌کنیم.',
	balanced: 'بدون فیلتر و بدون امتیاز: اتصال، بودجه و مشخصات ثبت‌شده تعیین‌کننده‌اند.',
	/* connection */
	wireless: 'فیلتر: فقط مدل‌هایی که اتصال بی‌سیم تأییدشده دارند.',
	usbc: 'فیلتر: فقط مدل‌هایی که صدای USB-C دارند؛ USB-C فقط برای شارژ کافی نیست.',
	aux: 'فیلتر: فقط مدل‌هایی که ورودی AUX تأییدشده دارند.',
	/* device */
	lightning: 'فیلتر: مدل USB-C برای دستگاه Lightning با اتصال مستقیم پیشنهاد نمی‌شود.',
	unknown: 'فیلتر: تا روشن‌شدن نوع درگاه دستگاه، هیچ مدل سیمی USB-C پیشنهاد نمی‌شود.',
	/* fit */
	silicone: 'فیلتر: فقط مدل‌هایی که سری سیلیکونی ثبت‌شده دارند می‌مانند.',
	open: 'فیلتر: فقط مدل‌هایی که صریحاً بدون سری سیلیکونی‌اند می‌مانند.',
	any: 'بدون فیلتر: هر دو فرم بررسی می‌شود.',
	long: 'فقط توضیح: داده فشار و گرمای گوش قابل مقایسه نیست؛ نکته راحتی کنار پیشنهاد می‌آید.',
	glasses: 'فقط توضیح: فشار روی دسته عینک عدد قابل مقایسه ندارد؛ نکته راحتی کنار پیشنهاد می‌آید.',
	/* calls */
	quiet: 'فقط توضیح: محیط آرام عدد قابل‌مقایسه‌ای نمی‌سازد؛ رتبه‌بندی تغییر نمی‌کند.',
	noisy: 'فقط توضیح: برای محیط شلوغ نمونه صدای میکروفون لازم است؛ رتبه‌بندی با داده نامعتبر تغییر نمی‌کند.',
};

export { questions, feedback, useLabels };
