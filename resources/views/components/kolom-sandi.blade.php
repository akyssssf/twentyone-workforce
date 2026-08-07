@props(['id', 'name', 'label' => null, 'autocomplete' => 'current-password', 'required' => true, 'minlength' => null])

<div>
    @if ($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
    @endif
    <div class="relative {{ $label ? 'mt-1' : '' }}">
        <input id="{{ $id }}" name="{{ $name }}" type="password"
               @if ($required) required @endif
               @if ($minlength) minlength="{{ $minlength }}" @endif
               autocomplete="{{ $autocomplete }}"
               {{ $attributes->merge(['class' => 'kolom pr-10']) }}>
        <button type="button"
                onclick="
                    const i = document.getElementById('{{ $id }}');
                    const tampil = i.type === 'text';
                    i.type = tampil ? 'password' : 'text';
                    this.querySelector('.mata-tutup').classList.toggle('hidden', !tampil);
                    this.querySelector('.mata-buka').classList.toggle('hidden', tampil);
                "
                class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition hover:text-slate-600"
                aria-label="Tampilkan atau sembunyikan kata sandi">
            <svg class="mata-tutup h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <svg class="mata-buka hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
    </div>
</div>
