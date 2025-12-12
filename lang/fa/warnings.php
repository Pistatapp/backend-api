<?php

return [
    // Warning messages
    'tractor_stoppage' => 'تراکتور با نام :tractor_name بیش از :hours ساعت در تاریخ :date متوقف بوده است. لطفا دلیل آن را بررسی کنید.',
    'tractor_inactivity' => 'تراکتور با نام :tractor_name بیش از :days روز غیرفعال بوده است. لطفا دلیل آن را بررسی کنید.',
    'irrigation_start_end' => 'عملیات آبیاری در تاریخ :start_date ساعت :start_time در قطعه :plot شروع شد و در ساعت :end_time به پایان رسید.',
    'frost_warning' => 'تا :days روز آینده احتمال سرمازدگی در باغ شما وجود دارد. اقدامات لازم را انجام دهید.',
    'radiative_frost_warning' => 'احتمال سرمازدگی تشعشعی در تاریخ :date وجود دارد. اقدامات لازم را انجام دهید.',
    'oil_spray_warning' => 'میزان نیاز سرمایی باغ از تاریخ :start_date تا تاریخ :end_date به میزان :hours ساعت بوده است. لطفا روغن‌پاشی را انجام دهید.',
    'pest_degree_day_warning' => 'میزان درجه روز آفت :pest از تاریخ :start_date تا تاریخ :end_date کمتر از عدد :degree_days بوده است.',
    'crop_type_degree_day_warning' => 'میزان درجه روز نوع محصول :crop_type از تاریخ :start_date تا تاریخ :end_date کمتر از عدد :degree_days بوده است.',

    // Setting messages
    'settings' => [
        'tractor_stoppage' => 'در صورتی که تراکتور بیش از :hours ساعت متوقف باشد، به من هشدار بده.',
        'tractor_inactivity' => 'در صورتی که بیش از :days روز از تراکتور اطلاعاتی دریافت نشود، به من هشدار بده.',
        'irrigation_start_end' => 'در شروع و پایان آبیاری به من هشدار بده.',
        'frost_warning' => ':days روز قبل از احتمال سرمازدگی به من هشدار بده.',
        'radiative_frost_warning' => 'در مورد خطر سرمازدگی تشعشعی به من هشدار بده.',
        'oil_spray_warning' => 'در صورتی که نیاز سرمایی از تاریخ :start_date تا :end_date کمتر از :hours ساعت باشد، به من هشدار بده.',
        'pest_degree_day_warning' => 'در صورتی که درجه روز آفت :pest از تاریخ :start_date تا :end_date کمتر از :degree_days باشد، به من هشدار بده.',
        'crop_type_degree_day_warning' => 'در صورتی که درجه روز نوع محصول :crop_type از تاریخ :start_date تا :end_date کمتر از :degree_days باشد، به من هشدار بده.',
    ],
];
