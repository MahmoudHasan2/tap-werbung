// 1. تفعيل السكرول الناعم (Lenis)
const lenis = new Lenis();
function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
}
requestAnimationFrame(raf);

// 2. توزيع المدار (Orbit Logic)
const orbit = document.getElementById('orbit');
const imageCount = 8;
const radius = 280;

const sampleImages = [
    'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=400',
    'https://images.unsplash.com/photo-1633356122544-f134324a6cee?w=400',
    'https://images.unsplash.com/photo-1620641788421-7a1c342ea42e?w=400',
    'https://images.unsplash.com/photo-1618005198919-d3d4b5a92ead?w=400',
    'https://images.unsplash.com/photo-1634017839464-5c339ebe3cb4?w=400',
    'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=400',
    'https://images.unsplash.com/photo-1558655146-d09347e92766?w=400',
    'https://images.unsplash.com/photo-1551288049-bbbda5366392?w=400'
];

for (let i = 0; i < imageCount; i++) {
    const angle = (i / imageCount) * Math.PI * 2;
    const x = Math.cos(angle) * radius;
    const y = Math.sin(angle) * radius;

    const div = document.createElement('div');
    div.className = 'orbit-item';
    div.innerHTML = `<img src="${sampleImages[i]}">`;
    
    gsap.set(div, { x: x, y: y, xPercent: -50, yPercent: -50 });
    orbit.appendChild(div);

    // تحريك كل صورة بشكل "عائم"
    gsap.to(div, {
        y: "+=30",
        x: "+=15",
        duration: 2 + Math.random() * 2,
        repeat: -1,
        yoyo: true,
        ease: "sine.inOut"
    });
}


const hamburger = document.querySelector('.hamburger');
const mobileMenu = document.getElementById('mobileMenu');

hamburger.addEventListener('click', () => {
    hamburger.classList.toggle('active');
    mobileMenu.classList.toggle('active');
    document.body.style.overflow = mobileMenu.classList.contains('active') ? 'hidden' : '';
});

// Close menu when clicking a link
document.querySelectorAll('.mobile-menu-inner a').forEach(link => {
    link.addEventListener('click', () => {
        hamburger.classList.remove('active');
        mobileMenu.classList.remove('active');
        document.body.style.overflow = '';
    });
});

// 3. تقطيع النصوص وتحريكها (Text Reveal)
const myText = new SplitType('.split', { types: 'chars' });
gsap.from(myText.chars, {
    y: 100,
    stagger: 0.02,
    duration: 1,
    ease: "power4.out",
    delay: 0.5
});

// 4. حركة الماوس
const cursor = document.getElementById('cursor');
window.addEventListener('mousemove', (e) => {
    gsap.to(cursor, { x: e.clientX, y: e.clientY, duration: 0.1 });
});

// 5. تفاعل المدار مع السكرول
gsap.to(".orbit-wrapper", {
    scrollTrigger: {
        trigger: ".hero",
        start: "top top",
        scrub: 1
    },
    rotation: 360,
    scale: 0.5,
    opacity: 0
});