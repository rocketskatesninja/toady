import { onMounted, onBeforeUnmount, ref } from 'vue';

// Lightweight scroll-reveal for the public pages: every `.reveal` element under the returned root fades in
// (gets `.in`) the first time it scrolls into view. The `.reveal` styles live in app.css.
export function useReveal() {
    const root = ref(null);
    let io = null;
    onMounted(() => {
        if (!('IntersectionObserver' in window)) return;
        io = new IntersectionObserver((entries) => {
            entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { threshold: 0.12 });
        root.value?.querySelectorAll('.reveal').forEach((el) => io.observe(el));
    });
    onBeforeUnmount(() => io?.disconnect());
    return root;
}
