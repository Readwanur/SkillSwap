const canvas = document.getElementById('bg-canvas');
if (canvas) {
    const ctx = canvas.getContext('2d');
    let width, height, cx, cy;
    let orbits = [];
    let particles = [];
    
    function resize() {
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = width;
        canvas.height = height;
        cx = width / 2;
        cy = height / 2;
    }
    window.addEventListener('resize', () => {
        resize();
        init();
    });
    resize();
    
    class Orbit {
        constructor(radius) {
            this.radius = radius;
            this.speed = (Math.random() * 0.0002 + 0.0001) * (Math.random() > 0.5 ? 1 : -1);
            this.angle = Math.random() * Math.PI * 2;
        }
        draw() {
            ctx.beginPath();
            ctx.arc(cx, cy, this.radius, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(115, 119, 129, 0.15)'; // Faint blue-gray orbital path
            ctx.lineWidth = 1;
            ctx.stroke();
        }
    }
    
    class Particle {
        constructor(orbit) {
            this.orbit = orbit;
            this.angle = Math.random() * Math.PI * 2;
            this.speed = (Math.random() * 0.0015 + 0.0005) * (Math.random() > 0.5 ? 1 : -1);
            this.size = Math.random() * 1.8 + 1.2;
            this.baseAlpha = Math.random() * 0.5 + 0.4;
            this.pulseSpeed = Math.random() * 0.02 + 0.01;
            this.pulseTime = Math.random() * Math.PI * 2;
        }
        update() {
            this.angle += this.speed;
            this.pulseTime += this.pulseSpeed;
        }
        draw() {
            const x = cx + Math.cos(this.angle) * this.orbit.radius;
            const y = cy + Math.sin(this.angle) * this.orbit.radius;
            
            const alpha = this.baseAlpha + Math.sin(this.pulseTime) * 0.2;
            
            ctx.beginPath();
            ctx.arc(x, y, this.size, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(255, 255, 255, ${alpha})`;
            ctx.shadowBlur = 12;
            ctx.shadowColor = `rgba(255, 255, 255, ${alpha})`;
            ctx.fill();
            ctx.shadowBlur = 0;
            
            // Trail
            ctx.beginPath();
            const trailLen = this.speed * 30;
            ctx.arc(cx, cy, this.orbit.radius, this.angle - trailLen, this.angle, this.speed < 0);
            ctx.strokeStyle = `rgba(0, 56, 108, ${alpha * 0.5})`; // Navy blue trail
            ctx.lineWidth = 2.5;
            ctx.stroke();
        }
    }
    
    function init() {
        orbits = [];
        particles = [];
        const maxRadius = Math.max(width, height) * 0.7;
        const numOrbits = Math.floor(maxRadius / 45); // Spacing between orbits
        
        for (let i = 1; i <= numOrbits; i++) {
            if (Math.random() > 0.1) { // 90% chance to create an orbit at this spacing
                const orbit = new Orbit(i * 45 + Math.random() * 15);
                orbits.push(orbit);
                
                const numParticles = Math.floor(Math.random() * 3) + 1;
                for (let j = 0; j < numParticles; j++) {
                    particles.push(new Particle(orbit));
                }
            }
        }
    }
    init();
    
    function animate() {
        ctx.clearRect(0, 0, width, height);
        
        const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.max(width, height) * 0.6);
        gradient.addColorStop(0, 'rgba(0, 56, 108, 0.04)');
        gradient.addColorStop(1, 'transparent');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);
        
        orbits.forEach(orbit => {
            orbit.angle += orbit.speed;
            orbit.draw();
        });
        
        particles.forEach(p => {
            p.update();
            p.draw();
        });
        
        requestAnimationFrame(animate);
    }
    
    animate();
}
