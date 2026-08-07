/**
 * Pengalih panel sederhana.
 *
 * Dipakai menu "Lainnya" di layar kecil dan menu akun. Sengaja tanpa framework:
 * yang dibutuhkan cuma membuka dan menutup satu elemen, dan menambah puluhan
 * kilobyte JavaScript untuk itu tidak sepadan — apalagi aplikasi ini sering
 * dibuka lewat jaringan seluler di kafe.
 *
 * Pemakaian:
 *   <button data-buka="menu-utama">…</button>
 *   <div id="menu-utama" data-panel hidden>…</div>
 */
/**
 * Sidebar diperlakukan khusus: ia digeser, bukan disembunyikan.
 *
 * Kalau ikut memakai atribut `hidden`, sidebar akan lenyap juga di layar lebar
 * — padahal di sana ia memang harus selalu terlihat.
 */
function alihkanSidebar(paksaTutup = false) {
    const sidebar = document.getElementById('sidebar');
    const tirai = document.getElementById('tirai-sidebar');

    if (!sidebar) {
        return;
    }

    const terbuka = !sidebar.classList.contains('-translate-x-full');
    const jadiTerbuka = paksaTutup ? false : !terbuka;

    sidebar.classList.toggle('-translate-x-full', !jadiTerbuka);
    tirai?.toggleAttribute('hidden', !jadiTerbuka);
}

document.addEventListener('click', (event) => {
    const pemicu = event.target.closest('[data-buka]');

    if (pemicu) {
        if (pemicu.dataset.buka === 'sidebar') {
            alihkanSidebar();
            pemicu.setAttribute('aria-expanded', String(!document.getElementById('sidebar').classList.contains('-translate-x-full')));

            return;
        }

        const target = document.getElementById(pemicu.dataset.buka);

        if (target) {
            const sedangTertutup = target.hasAttribute('hidden');

            tutupSemua(sedangTertutup ? target : null);
            target.toggleAttribute('hidden', !sedangTertutup);
            pemicu.setAttribute('aria-expanded', String(sedangTertutup));
        }

        return;
    }

    // Klik di luar panel menutupnya. Tanpa ini, menu yang terbuka di layar
    // kecil menutupi konten dan pengguna harus menebak cara menutupnya.
    if (!event.target.closest('[data-panel]')) {
        tutupSemua(null);
    }

    // Termasuk klik pada tirai gelap di belakang sidebar.
    if (!event.target.closest('#sidebar')) {
        alihkanSidebar(true);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        tutupSemua(null);
        alihkanSidebar(true);
    }
});

function tutupSemua(kecuali) {
    document.querySelectorAll('[data-panel]').forEach((panel) => {
        if (panel !== kecuali) {
            panel.setAttribute('hidden', '');
        }
    });

    document.querySelectorAll('[data-buka]').forEach((pemicu) => {
        const target = document.getElementById(pemicu.dataset.buka);
        pemicu.setAttribute('aria-expanded', String(target === kecuali));
    });
}
