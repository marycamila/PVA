<!doctype html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">

    <title>{{ config('app.name') }}</title>

    <!-- Fonts -->
    @if (config('app.debug'))
      <link href="https://fonts.googleapis.com/css?family=Raleway:100,600" rel="stylesheet" type="text/css">
    @endif

    <!-- Styles -->
    <link href="{{ asset('css/app.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/all.css') }}" rel="stylesheet" type="text/css">
  </head>
  <body>
    <div id="app">
      <app-main/>
    </div>
    @if(config('app.debug'))
      <script src="http://localhost:35729/livereload.js"></script>
    @endif
    <script src="{{ asset('js/app.js') }}"></script>
  </body>
</html>