{{-- Language buttons for the pages before signing in (login, forgot and reset password). --}}
<form method="POST" action="{{ route('locale.update') }}" {{ $attributes->class('mt-8 flex justify-center gap-1') }}>
    @csrf
    @foreach (\App\Services\LocaleService::available() as $code => $name)
        <flux:button type="submit" name="locale" value="{{ $code }}" size="sm" :variant="app()->getLocale() === $code ? 'filled' : 'ghost'" lang="{{ $code }}">{{ $name }}</flux:button>
    @endforeach
</form>
