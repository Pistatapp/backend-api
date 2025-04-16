<p align="center"><a href="#" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About PiStat

PiStat is a comprehensive agricultural management system built with Laravel. It provides farmers and agricultural businesses with powerful tools to monitor and manage their farming operations efficiently. Some key features include:

- **Farm Management**: Create, view, and manage multiple farms and fields.
- **Tractor & Equipment Monitoring**: Track tractor movements, tasks, and efficiency in real time using GPS technology.
- **Irrigation Control**: Schedule and monitor irrigation programs across different fields.
- **Labor Management**: Organize farm workers, create teams, and track working hours.
- **Treatment Planning**: Create and manage agricultural treatment plans for fields.
- **Farm Analytics**: Generate detailed reports about farm operations and productivity.
- **Real-time Notifications**: Receive alerts about important farm events and status changes.
- **Weather Integration**: Access weather forecasts for better planning of farm operations.
- **Pest Management**: Track and manage pest control activities.

## Getting Started

### Prerequisites

- PHP 8.0 or higher
- Composer
- MySQL or compatible database
- Node.js and NPM for asset compilation

### Installation

1. Clone the repository
```
git clone https://your-repository-url/pistat.git
```

2. Install PHP dependencies
```
composer install
```

3. Install JavaScript dependencies
```
npm install
```

4. Create a copy of your .env file
```
cp .env.example .env
```

5. Generate an app encryption key
```
php artisan key:generate
```

6. Configure your database in the .env file
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pistat
DB_USERNAME=root
DB_PASSWORD=
```

7. Run database migrations
```
php artisan migrate
```

8. Seed the database (optional)
```
php artisan db:seed
```

9. Start the local development server
```
php artisan serve
```

## API Documentation

The following API documentation is available:

- [User Preferences](docs/api/user-preferences.md) - Endpoints for managing user preferences
- [Active Tractor](docs/api/active-tractor.md) - Endpoints for tractor monitoring and management
- [Maintenance Reports](docs/api/maintenance-reports.md) - Endpoints for maintenance reporting
- [Nutrient Diagnosis](docs/api/nutrient-diagnosis.md) - Endpoints for soil nutrient analysis
- [Warning System](docs/api/warnings.md) - Endpoints for the farm warning system

## Contributing

Thank you for considering contributing to PiStat! Please review the [contribution guidelines](CONTRIBUTING.md) before submitting pull requests.

## Security Vulnerabilities

If you discover a security vulnerability within PiStat, please send an e-mail via [contact@example.com](mailto:contact@example.com). All security vulnerabilities will be promptly addressed.

## License

The PiStat application is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
