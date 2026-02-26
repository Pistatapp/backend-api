<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Verify code') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />
    <style>
        *,::after,::before{box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb}
        body{margin:0;font-family:Figtree,sans-serif;line-height:1.5;background-color:rgb(243 244 246)}
        .min-h-screen{min-height:100vh}
        .flex{display:flex}
        .items-center{align-items:center}
        .justify-center{justify-content:center}
        .p-6{padding:1.5rem}
        .max-w-md{max-width:28rem}
        .w-full{width:100%}
        .bg-white{background-color:#fff}
        .rounded-lg{border-radius:0.5rem}
        .shadow-xl{box-shadow:0 20px 25px -5px rgb(0 0 0/0.1),0 8px 10px -6px rgb(0 0 0/0.1)}
        .text-gray-900{color:rgb(17 24 39)}
        .text-gray-600{color:rgb(75 85 99)}
        .text-sm{font-size:0.875rem}
        .text-xl{font-size:1.25rem}
        .font-semibold{font-weight:600}
        .mt-4{margin-top:1rem}
        .mt-6{margin-top:1.5rem}
        .mb-2{margin-bottom:0.5rem}
        .block{display:block}
        input{border:1px solid #d1d5db;border-radius:0.375rem;padding:0.5rem 0.75rem;width:100%}
        input:focus{outline:2px solid #ef4444;outline-offset:2px;border-color:#ef4444}
        .border-red-500{border-color:#ef4444}
        .text-red-600{color:#dc2626;font-size:0.875rem;margin-top:0.25rem}
        button{background-color:#ef4444;color:#fff;padding:0.5rem 1rem;border-radius:0.375rem;font-weight:600;cursor:pointer;border:none;width:100%}
        button:hover{background-color:#dc2626}
        a{color:#ef4444;text-decoration:none;font-size:0.875rem}
        a:hover{text-decoration:underline}
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="max-w-md w-full bg-white rounded-lg shadow-xl p-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ __('Verify code') }}</h1>
        <p class="text-sm text-gray-600 mt-2">{{ __('Enter the 6-digit code sent to your mobile.') }}</p>

        @if ($errors->any())
            <div class="mt-4 p-3 bg-red-50 rounded border border-red-200">
                @foreach ($errors->all() as $error)
                    <p class="text-sm text-red-600">{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('telescope.verify') }}" class="mt-6">
            @csrf
            <label for="password" class="block text-sm font-medium text-gray-900 mb-2">{{ __('Verification code') }}</label>
            <input id="password" type="text" name="password" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000"
                   class="@error('password') border-red-500 @enderror">
            @error('password')
                <p class="text-red-600">{{ $message }}</p>
            @enderror
            <button type="submit" class="mt-4">{{ __('Verify') }}</button>
        </form>

        <p class="mt-4 text-center">
            <a href="{{ route('telescope.login') }}">{{ __('Use a different mobile number') }}</a>
        </p>
    </div>
</body>
</html>
