<?php

return [
    // Warning messages
    'tractor_stoppage' => 'Tractor name :tractor_name has been stopped for more than :hours hours on :date. Please check the reason.',
    'tractor_inactivity' => 'Tractor name :tractor_name has been inactive for more than :days days. Please check the reason.',
    'irrigation_start_end' => 'Irrigation operation started on :start_date at :start_time in plot :plot and ended at :end_time.',
    'frost_warning' => 'There is a risk of frost in your farm in the next :days days. Take precautions.',
    'radiative_frost_warning' => 'There is a risk of radiative frost on :date. Take precautions.',
    'oil_spray_warning' => 'The chilling requirement in your farm from :start_date to :end_date was :hours hours. Please perform oil spraying.',
    'pest_degree_day_warning' => 'The degree days for :pest pest from :start_date to :end_date was :degree_days.',
    'crop_type_degree_day_warning' => 'The degree days for :crop_type crop_type from :start_date to :end_date was :degree_days.',

    // Setting messages
    'settings' => [
        'tractor_stoppage' => 'Warn me if a tractor stops for more than :hours hours.',
        'tractor_inactivity' => 'Warn me if no data is received from a tractor for more than :days days.',
        'irrigation_start_end' => 'Warn me at the start and end of irrigation.',
        'frost_warning' => 'Warn me :days days before a potential frost event.',
        'radiative_frost_warning' => 'Warn me about radiative frost risk.',
        'oil_spray_warning' => 'Warn me if chilling requirement from :start_date to :end_date is less than :hours hours.',
        'pest_degree_day_warning' => 'Warn me if degree days for :pest pest from :start_date to :end_date is less than :degree_days.',
        'crop_type_degree_day_warning' => 'Warn me if degree days for :crop_type crop_type from :start_date to :end_date is less than :degree_days.',
    ],
];