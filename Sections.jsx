import React from "react";
import "../styles/Global.css";
import Pricing from "../components/Pricing";
import "../styles/FaqSection.css";
import { useState,useRef, useEffect} from "react";
// import "../styles/Services.css";
import "../styles/About.css";
import "../styles/Work.css";


export const Hero = () => {
  const [mousePos, setMousePos] = useState({ x: 0, y: 0 });

  // Handle cursor movement to calculate offset
  const handleMouseMove = (e) => {
    const { clientX, clientY } = e;
    // Calculate movement from -1 to 1 based on screen center
    const x = (clientX / window.innerWidth - 0.5) * 2;
    const y = (clientY / window.innerHeight - 0.5) * 2;
    setMousePos({ x, y });
  };

  return (
    <section className="hero" onMouseMove={handleMouseMove}>
      <style>{`
        .hero {
          min-height: 100vh;
          position: relative;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          display: flex;
          align-items: center;
          padding-top: 100px;
          overflow: hidden;
          perspective: 1000px; /* Crucial for 3D effect */
        }

        .hero-container {
          display: flex;
          flex-direction: column;
          gap: 60px;
          width: 100%;
        }

        @media (min-width: 1024px) {
          .hero-container { flex-direction: row; align-items: center; }
        }

        /* --- PARALLAX LAYERS --- */
        .geometric-wrap {
          position: relative;
          width: 100%;
          max-width: 600px;
          height: 500px;
          display: flex;
          justify-content: center;
          align-items: center;
        }

        /* The Main Image Frame */
        .main-frame {
          width: 85%;
          height: 100%;
          clip-path: polygon(15% 0%, 100% 0%, 85% 100%, 0% 100%);
          border-right: 12px solid var(--pepa-yellow);
          overflow: hidden;
          /* Uses State for Movement */
          transform: translate(
            ${mousePos.x * 15}px, 
            ${mousePos.y * 15}px
          );
          transition: transform 0.2s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .main-frame img {
          width: 110%; /* Slightly larger to allow room for movement */
          height: 110%;
          object-fit: cover;
          transform: translate(-5%, -5%);
        }

        /* The Dark Accent Card (Moves more for depth) */
        .accent-card {
          position: absolute;
          bottom: 10%;
          left: -5%;
          background: var(--dark);
          color: white;
          padding: 30px;
          width: 240px;
          border-radius: 0 40px 0 40px;
          box-shadow: 20px 20px 0px var(--pepa-yellow);
          z-index: 20;
          /* Higher multiplier = Moves faster = Appears closer to user */
          transform: translate(
            ${mousePos.x * -35}px, 
            ${mousePos.y * -35}px
          );
          transition: transform 0.15s cubic-bezier(0.23, 1, 0.32, 1);
        }

        /* Glow Follower (Subtle background glow that follows mouse) */
        .mouse-glow {
          position: absolute;
          width: 600px;
          height: 600px;
          background: radial-gradient(circle, rgba(254, 224, 100, 0.15) 0%, transparent 70%);
          top: 0;
          left: 0;
          pointer-events: none;
          transform: translate(
            calc(${mousePos.x * 50}vw + 25vw), 
            calc(${mousePos.y * 50}vh + 25vh)
          );
          z-index: 0;
        }

        .hero-title {
          font-size: clamp(3rem, 7vw, 5rem);
          font-weight: 900;
          line-height: 0.95;
          text-transform: uppercase;
        }

        .hero-title span {
        font-size: clamp(4rem, 8vw, 6rem);
          display: block;
          color: var(--pepa-yellow);
          -webkit-text-stroke: 1px var(--dark);
        }

        @media (max-width: 900px) {
          /* Disable parallax on mobile for performance and better UX */
          .main-frame, .accent-card { transform: none !important; }
          .hero { text-align: center; }
        }
      `}</style>

      {/* Floating ambient glow that follows cursor */}
      <div className="mouse-glow"></div>

      <div className="page-wrap">
        <div className="hero-container">
          
          <div className="hero-content">
            <div className="contact-badge">PREMIUM AGENCY</div>
            
            <h1 className="hero-title">
              GROWING <br />
              <span>BUSINESS</span>
              BEYOND
            </h1>
            <p className="contact-description">
              We re-engineer your digital presence. PEPA is the catalyst for brands ready to dominate.
            </p>
            <div className="hero-actions">
              <a href="#contact" className="hero-btn primary">Elevate Now</a>
              <a href="#services" className="hero-btn secondary" style={{border: 'none'}}>View Services →</a>
            </div>
          </div>

          <div className="hero-visual">
            <div className="geometric-wrap">
              <div className="main-frame">
                <img 
                  src="https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&q=80&w=800" 
                  alt="Team Performance" 
                />
              </div>

              <div className="accent-card">
                <h4 style={{fontSize: '1.5rem', marginBottom: '10px'}}>10X</h4>
                <p style={{fontSize: '0.75rem', opacity: '0.7', textTransform: 'uppercase'}}>
                  Average Performance Increase
                </p>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>
  );
};

/* ================= SERVICES ================= */
export const Services = () => {
  const sectionRef = useRef(null);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('active');
          } else {
            entry.target.classList.remove('active');
          }
        });
      },
      { threshold: 0.1 }
    );

    const elements = sectionRef.current.querySelectorAll('.service-row');
    elements.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  const services = [
    {
      id: "01",
      title: "Social Media Marketing",
      desc: "Platform-specific content and high-energy reels that build brand trust.",
      points: ["Instagram & LinkedIn", "Reels & Posters"],
      grade: "rgba(254, 224, 100, 0.2)" 
    },
    {
      id: "02",
      title: "SEO & Management",
      desc: "Search-optimized websites designed to rank higher and scale faster.",
      points: ["Technical SEO", "Performance"],
      grade: "rgba(254, 224, 100, 0.5)" 
    },
    {
      id: "03",
      title: "Premium Branding",
      desc: "Cinematic video production and custom strategies for market leaders.",
      points: ["Identity", "Paid Campaigns"],
      grade: "rgba(254, 224, 100, 0.9)" 
    }
  ];

  return (
    <section className="services-page" id="services" ref={sectionRef}>
      <style>{`
        .services-page {
          
          padding: 100px 0;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          overflow: hidden;
        }

        .services-header {
          margin-bottom: 50px; /* Reduced margin */
        }

        .services-stack {
          display: flex;
          flex-direction: column;
          gap: 15px; /* Tighter gap */
        }

        /* --- STREAMLINED ROW STYLE --- */
        .service-row {
          opacity: 0;
          transform: translateY(20px);
          transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
          background: white;
          border-radius: 20px; /* More compact corners */
          border: 1px solid #f3f4f6;
          display: flex;
          flex-direction: column;
          position: relative;
          overflow: hidden;
        }

        .service-row.active {
          opacity: 1;
          transform: translateY(0);
        }

        @media (min-width: 1024px) {
          .service-row { flex-direction: row; align-items: center; min-height: 140px; }
        }

        /* --- COMPACT NUMBERS --- */
        .service-id {
          font-size: 3.5rem; /* Reduced from 7rem */
          font-weight: 900;
          color: transparent;
          -webkit-text-stroke: 1px #e5e7eb;
          margin-left: 30px;
          transition: 0.6s ease;
        }

        .service-row.active .service-id {
          -webkit-text-stroke: 1px var(--pepa-yellow);
          color: rgba(254, 224, 100, 0.1);
        }

        .service-main { padding: 25px 40px; flex: 1; }
        .service-title { font-size: 1.6rem; font-weight: 900; text-transform: uppercase; margin-bottom: 8px; }
        .service-text { font-size: 0.95rem; color: #6b7280; line-height: 1.5; max-width: 500px; }

        .grade-bar {
          position: absolute;
          left: 0; top: 0; bottom: 0; width: 8px;
          background: var(--pepa-yellow);
        }

        /* --- COMPACT ACTION --- */
        .arrow-action {
          width: 50px; height: 50px; /* Reduced from 80px */
          background: var(--dark); color: white;
          display: flex; align-items: center; justify-content: center;
          border-radius: 50%; text-decoration: none; font-size: 1.2rem;
          margin-right: 30px; transition: 0.3s;
        }

        .arrow-action:hover {
          background: var(--pepa-yellow);
          color: var(--dark);
          transform: translateX(5px);
        }

        @media (max-width: 1024px) {
          .service-row { margin: 0 15px; }
          .service-id { font-size: 2.5rem; margin-top: 15px; }
          .arrow-action { width: 100%; height: 50px; border-radius: 0; margin: 0; }
          .service-main { padding: 20px; }
        }
      `}</style>

      <div className="page-wrap">
        {/* --- HEADER TITLE --- */}
        <div className="services-header">
          <span className="contact-badge" style={{ background: 'var(--pepa-yellow)', padding: '5px 15px', borderRadius: '99px', fontSize: '0.65rem', fontWeight: '800' }}>
            WHAT WE DO
          </span>
          <h2 style={{ fontSize: 'clamp(2rem, 5vw, 3.5rem)', fontWeight: '950', marginTop: '15px', textTransform: 'uppercase', lineHeight: '1' }}>
            DIGITAL <span style={{ color: 'var(--pepa-yellow)', WebkitTextStroke: '1px var(--dark)' }}>SOLUTIONS</span>
          </h2>
        </div>

        {/* --- STACK --- */}
        <div className="services-stack">
          {services.map((s, index) => (
            <div key={s.id} className="service-row" style={{ transitionDelay: `${index * 0.1}s` }}>
              <div className="grade-bar" style={{ background: s.grade }}></div>
              <div className="service-id">{s.id}</div>
              
              <div className="service-main">
                <h3 className="service-title">{s.title}</h3>
                <p className="service-text">{s.desc}</p>
                
                <div style={{marginTop: '15px', display: 'flex', gap: '8px'}}>
                  {s.points.map((p, i) => (
                    <span key={i} style={{ fontSize: '0.7rem', fontWeight: '700', padding: '4px 12px', background: '#f9f9f9', border: `1px solid ${s.grade}`, borderRadius: '99px' }}>
                      {p}
                    </span>
                  ))}
                </div>
              </div>

              <a href="#contact" className="arrow-action">→</a>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};
/* ================= ABOUT ================= */

export const About = () => {
  const sectionRef = useRef(null);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('active');
          } else {
            entry.target.classList.remove('active');
          }
        });
      },
      { threshold: 0.2 }
    );

    const elements = sectionRef.current.querySelectorAll('.reveal-el');
    elements.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  return (
    <section className="about-section" id="about" ref={sectionRef}>
      <style>{`
        .about-section {
          padding: 100px 0;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          overflow: hidden;
        }

        .about-container {
          display: grid;
          grid-template-columns: 1fr;
          gap: 60px;
          align-items: center;
        }

        @media (min-width: 1024px) {
          .about-container {
            grid-template-columns: 1.1fr 0.9fr;
          }
        }

        /* --- LEFT CONTENT --- */
        .reveal-el {
          opacity: 0;
          transform: translateY(30px);
          transition: all 0.8s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .reveal-el.active {
          opacity: 1;
          transform: translateY(0);
        }

        .about-title {
          font-size: clamp(2.5rem, 6vw, 4rem);
          font-weight: 950;
          text-transform: uppercase;
          letter-spacing: -2px;
          line-height: 1;
          margin: 20px 0;
        }

        .about-title span {
          color: var(--pepa-yellow);
          -webkit-text-stroke: 1px var(--dark);
        }

        .about-text {
          font-size: 1.1rem;
          color: #4b5563;
          line-height: 1.7;
          margin-bottom: 25px;
        }

        .about-points {
          list-style: none;
          padding: 0;
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 15px;
        }

        .about-points li {
          font-weight: 800;
          font-size: 0.85rem;
          text-transform: uppercase;
          display: flex;
          align-items: center;
          gap: 10px;
        }

        .about-points li::before {
          content: '✓';
          color: var(--dark);
          background: var(--pepa-yellow);
          width: 20px;
          height: 20px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          font-size: 0.6rem;
        }

        /* --- RIGHT GEOMETRIC CARDS --- */
        .about-right {
          position: relative;
          display: flex;
          flex-direction: column;
          gap: 20px;
        }

        .about-card {
          background: white;
          padding: 40px;
          border-radius: 0 40px 0 40px;
          border: 1px solid rgba(0,0,0,0.05);
          box-shadow: 0 20px 40px rgba(0,0,0,0.05);
          transition: 0.4s ease;
          position: relative;
          overflow: hidden;
        }

        .about-card:hover {
          transform: translateX(-10px);
          border-color: var(--pepa-yellow);
        }

        .about-card.mission {
          border-left: 8px solid var(--pepa-yellow);
        }

        .about-card.vision {
          background: var(--dark);
          color: white;
          border-left: 8px solid var(--pepa-yellow);
          align-self: flex-end;
          width: 90%;
        }

        .about-card h3 {
          font-size: 1.5rem;
          font-weight: 900;
          text-transform: uppercase;
          margin-bottom: 10px;
        }

        .about-card p {
          font-size: 0.95rem;
          opacity: 0.8;
          line-height: 1.6;
        }

        /* Decorative Ghost Text */
        .ghost-bg {
          position: absolute;
          font-size: 8rem;
          font-weight: 900;
          color: rgba(0,0,0,0.02);
          bottom: -20px;
          left: -10px;
          z-index: 0;
          pointer-events: none;
        }

        @media (max-width: 768px) {
          .about-points { grid-template-columns: 1fr; }
          .about-card.vision { width: 100%; align-self: flex-start; }
        }
      `}</style>

      <div className="page-wrap">
        <div className="about-container">
          
          {/* Left Side */}
          <div className="about-left">
            <span className="contact-badge reveal-el">ABOUT THE AGENCY</span>
            <h2 className="about-title reveal-el">
              Branding Solutions <br /> <span>Experts</span>
            </h2>
            
            <p className="about-text reveal-el">
              At <strong>PEPA Branding Solutions</strong>, we help businesses
              transform ideas into powerful digital brands. We combine strategy,
              creativity, and performance marketing to deliver measurable growth.
            </p>

            <p className="about-text reveal-el">
              From SEO and social media to branding and paid advertising, our team
              focuses on building a strong online presence and high engagement.
            </p>

            <ul className="about-points reveal-el">
              <li>Result-driven marketing</li>
              <li>Custom strategies</li>
              <li>Transparent reporting</li>
              <li>Dedicated experts</li>
            </ul>
          </div>

          {/* Right Side */}
          <div className="about-right">
            <div className="ghost-bg">PEPA</div>
            
            <div className="about-card mission reveal-el">
              <h3>Our Mission</h3>
              <p>
                Empower businesses with digital strategies that generate
                sustainable growth and long-term brand value.
              </p>
            </div>

            <div className="about-card vision reveal-el">
              <h3>Our Vision</h3>
              <p>
                To become the global standard for digital growth, bridging the gap between 
                ambitious ideas and market-dominating brands.
              </p>
            </div>
          </div>

        </div>
      </div>
    </section>
  );
};

/* ================= WORK ================= */
export const Work = () => {
  const sectionRef = useRef(null);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('active');
          } else {
            entry.target.classList.remove('active');
          }
        });
      },
      { threshold: 0.1 }
    );

    const cards = sectionRef.current?.querySelectorAll('.work-card');
    cards?.forEach((card) => observer.observe(card));

    return () => observer.disconnect();
  }, []);

  const works = [
    { 
      title: "Brand Campaigns", 
      size: "large", 
      details: "Comprehensive brand positioning and visual storytelling.",
      animType: "float-x" 
    },
    { 
      title: "Web Development", 
      size: "small", 
      details: "High-performance SEO architecture for educational and corporate platforms.",
      links: [
        { name: "Live Site 1", url: "https://greencitydegreecollege.in" },
        { name: "Live Site 2", url: "https://gcdc.pages.dev/" }
      ],
      animType: "float-y"
    },
    { title: "Social Growth", size: "small", details: "Turning followers into brand advocates with cinematic content.", animType: "float-x" },
    { title: "E-comm Marketing", size: "medium", details: "Scaling stores through optimized conversion funnels.", animType: "float-y" },
    { title: "Lead Gen Funnels", size: "medium", details: "Automated B2B systems for lead acquisition.", animType: "float-x" },
    { title: "Video Marketing", size: "small", details: "High-impact short-form content that captures attention.", animType: "float-y" },
    { 
      title: "Corporate Gifting", 
      size: "large", 
      details: "Bespoke, branded gifting solutions for elite corporate engagement.",
      animType: "float-x"
    }
  ];

  return (
    <section className="work-section" id="work" ref={sectionRef}>
      <style>{`
        .work-section {
          padding: 100px 0;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          overflow: hidden;
        }

        .work-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
          gap: 25px;
          max-width: 1200px;
          margin: 0 auto;
        }

        .work-card {
          opacity: 0;
          background: rgba(255, 255, 255, 0.8);
          backdrop-filter: blur(10px);
          border-radius: 30px;
          padding: 40px;
          min-height: 280px;
          position: relative;
          overflow: hidden;
          display: flex;
          flex-direction: column;
          justify-content: flex-end;
          border: 1px solid rgba(0,0,0,0.05);
          transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .work-card.active { opacity: 1; }

        /* --- IDLE MOVING ANIMATIONS --- */
        .float-x { animation: floatX 6s ease-in-out infinite; }
        .float-y { animation: floatY 8s ease-in-out infinite; }

        @keyframes floatX {
          0%, 100% { transform: translateX(0); }
          50% { transform: translateX(15px); }
        }

        @keyframes floatY {
          0%, 100% { transform: translateY(0); }
          50% { transform: translateY(-15px); }
        }

        /* Stop animation on hover and change background */
        .work-card:hover {
          animation-play-state: paused;
          background: var(--dark); /* Matches the section's secondary dark theme */
          color: white;
          border-color: var(--pepa-yellow);
          transform: scale(1.05) !important;
          z-index: 10;
        }

        .work-card h3 {
          font-size: 1.5rem;
          font-weight: 900;
          text-transform: uppercase;
          transition: 0.3s;
        }

        /* --- INTERNAL CONTENT REVEAL --- */
        .hover-content {
          max-height: 0;
          opacity: 0;
          overflow: hidden;
          transition: all 0.5s ease;
        }

        .work-card:hover .hover-content {
          max-height: 200px;
          opacity: 1;
          margin-top: 15px;
        }

        .work-card:hover h3 {
          color: var(--pepa-yellow);
        }

        .work-link-btn {
          display: inline-block;
          margin-top: 15px;
          margin-right: 10px;
          padding: 8px 16px;
          background: var(--pepa-yellow);
          color: var(--dark);
          border-radius: 99px;
          text-decoration: none;
          font-size: 0.75rem;
          font-weight: 800;
          text-transform: uppercase;
        }

        @media (min-width: 1024px) {
          .work-grid { grid-template-columns: repeat(3, 1fr); }
          .work-card.large { grid-column: span 2; }
        }

        @media (max-width: 768px) {
          .work-card { animation: none; transform: translateY(0); }
        }
      `}</style>

      <div className="page-wrap">
        <div style={{textAlign: 'center', marginBottom: '60px'}}>
          <span className="contact-badge">Portfolio</span>
          <h2 className="work-title" style={{fontSize: 'clamp(2.5rem, 6vw, 4rem)', fontWeight: 950, textTransform: 'uppercase'}}>
            Selected <span>Projects</span>
          </h2>
        </div>

        <div className="work-grid">
          {works.map((item, i) => (
            <div 
              key={i} 
              className={`work-card ${item.animType} ${item.size}`}
              style={{transitionDelay: `${i * 0.1}s`}}
            >
              <h3>{item.title}</h3>
              
              <div className="hover-content">
                <p style={{fontSize: '0.9rem', lineHeight: '1.5'}}>{item.details}</p>
                <div style={{display: 'flex', gap: '10px', marginTop: '10px', flexWrap: 'wrap'}}>
                  {item.links ? item.links.map((link, idx) => (
                    <a key={idx} href={link.url} target="_blank" rel="noreferrer" className="work-link-btn">
                      {link.name} ↗
                    </a>
                  )) : (
                    <a href="#contact" className="work-link-btn">Enquire ↗</a>
                  )}
                </div>
              </div>

              {/* Decorative background ghost text */}
              <div style={{
                position: 'absolute', top: '10px', right: '10px',
                fontSize: '4rem', fontWeight: 900, color: 'rgba(0,0,0,0.02)',
                pointerEvents: 'none'
              }}>PEPA</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

export const Achievements = () => {
  const sectionRef = useRef(null);
  const [isVisible, setIsVisible] = useState(false);

  const stats = [
    { label: "Projects in Pipeline", value: 5 },
    { label: "Strategies Designed", value: 12 },
    { label: "Industries Targeted", value: 8 },
    { label: "Launched In", value: 2026, noAnim: true }
  ];

  const [counts, setCounts] = useState(stats.map(() => 0));

  // Trigger animation only when in view
  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) setIsVisible(true);
      },
      { threshold: 0.3 }
    );
    if (sectionRef.current) observer.observe(sectionRef.current);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!isVisible) return;

    const interval = setInterval(() => {
      setCounts(prev =>
        prev.map((c, i) => {
          if (stats[i].noAnim) return stats[i].value;
          return c < stats[i].value ? c + 1 : c;
        })
      );
    }, 60);

    return () => clearInterval(interval);
  }, [isVisible]);

  // 3D Tilt Logic
  const handleMouseMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width - 0.5) * 20;
    const y = ((e.clientY - rect.top) / rect.height - 0.5) * -20;
    e.currentTarget.style.transform = `perspective(1000px) rotateX(${y}deg) rotateY(${x}deg) scale3d(1.02, 1.02, 1.02)`;
  };

  const handleMouseLeave = (e) => {
    e.currentTarget.style.transform = `perspective(1000px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)`;
  };

  return (
    <section className="achievements-section" ref={sectionRef}>
      <style>{`
        .achievements-section {
          padding: 100px 0;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          text-align: center;
          overflow: hidden;
        }

        .achievements-title {
          font-size: clamp(2.5rem, 6vw, 4rem);
          font-weight: 950;
          text-transform: uppercase;
          letter-spacing: -2px;
          line-height: 1;
          margin: 20px 0;
        }

        .achievements-title span {
          color: var(--pepa-yellow);
          -webkit-text-stroke: 1px var(--dark);
        }

        .achievements-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
          gap: 25px;
          margin-top: 60px;
          perspective: 1500px;
        }

        .achievement-card {
          background: white;
          padding: 50px 30px;
          border-radius: 35px;
          border: 1px solid rgba(0,0,0,0.05);
          position: relative;
          transition: transform 0.1s ease-out, box-shadow 0.3s ease;
          transform-style: preserve-3d;
          overflow: hidden;
        }

        .achievement-card:hover {
          box-shadow: 0 40px 80px rgba(0,0,0,0.1);
          border-color: var(--pepa-yellow);
        }

        .achievement-card h3 {
          font-size: 4rem;
          font-weight: 950;
          color: var(--dark);
          margin-bottom: 10px;
          transform: translateZ(50px);
        }

        .achievement-card p {
          font-size: 0.9rem;
          font-weight: 800;
          color: #6b7280;
          text-transform: uppercase;
          letter-spacing: 1px;
          transform: translateZ(30px);
        }

        /* Geometric Background Ghost Text */
        .achievement-card::before {
          content: 'DATA';
          position: absolute;
          bottom: -10px;
          right: -10px;
          font-size: 5rem;
          font-weight: 900;
          color: rgba(0,0,0,0.02);
          z-index: 0;
          pointer-events: none;
        }

        @media (max-width: 768px) {
          .achievement-card { padding: 40px 20px; }
          .achievement-card h3 { font-size: 3rem; }
          .achievements-title { font-size: 2.2rem; }
        }
      `}</style>

      <div className="page-wrap">
        <span className="contact-badge" style={{ background: 'var(--pepa-yellow)', padding: '6px 18px', borderRadius: '99px', fontSize: '0.7rem', fontWeight: '800' }}>
          MILESTONES
        </span>

        <h2 className="achievements-title">
          Building Momentum <br /> <span>From Day One</span>
        </h2>

        <p className="contact-description" style={{ margin: '0 auto', maxWidth: '650px' }}>
          We may be new, but our foundation is solid. PEPA is built on 
          calculated strategy, digital dominance, and execution excellence.
        </p>

        <div className="achievements-grid">
          {stats.map((s, i) => (
            <div 
              key={i} 
              className="achievement-card"
              onMouseMove={handleMouseMove}
              onMouseLeave={handleMouseLeave}
            >
              <h3>{counts[i]}{!s.noAnim && "+"}</h3>
              <p>{s.label}</p>
              
              <div style={{
                position: 'absolute', top: 0, left: 0, width: '100%', height: '5px',
                background: i % 2 === 0 ? 'var(--pepa-yellow)' : 'var(--dark)'
              }}></div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

/* ================= FAQ ================= */


export const FAQ = () => {
  const sectionRef = useRef(null);
  const [activeIndex, setActiveIndex] = useState(0); // Default to first item

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) entry.target.classList.add('active-el');
          else entry.target.classList.remove('active-el');
        });
      },
      { threshold: 0.1 }
    );
    const elements = sectionRef.current?.querySelectorAll('.reveal-el');
    elements?.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, []);

  const faqs = [
    {
      question: "Do you work with startups?",
      answer: "Yes. We work with startups, SMEs, and enterprises. Every strategy is customized based on your specific growth goals, budget, and market stage."
    },
    {
      question: "Is SEO included in all plans?",
      answer: "SEO is standard in our Professional and Premium tiers. We also offer specialized SEO audits as standalone add-ons for brands looking for quick technical wins."
    },
    {
      question: "How soon can I see results?",
      answer: "Most clients observe measurable digital growth within 60–90 days, depending on market competition and the intensity of the strategies deployed."
    },
    {
      question: "Do you provide custom branding?",
      answer: "Absolutely. We conduct deep market analysis to build a cinematic brand roadmap that separates you from the competition and builds long-term authority."
    },
    {
      question: "Can I upgrade my plan later?",
      answer: "Yes. Our partnership models are flexible. You can scale your services up or down as your business evolves and your marketing needs grow."
    }
  ];

  return (
    <section className="faq-page" id="faqs" ref={sectionRef}>
      <style>{`
        .faq-page {
          padding: 100px 0;
          background: radial-gradient(circle at top right, rgba(254, 224, 100, 0.35), #ffffff 70%);
          overflow: hidden;
        }

        .faq-title-area {
          text-align: center;
          margin-bottom: 60px;
        }

        /* --- DESKTOP DASHBOARD LAYOUT --- */
        .faq-dashboard {
          display: grid;
          grid-template-columns: 1fr;
          gap: 40px;
          max-width: 1200px;
          margin: 0 auto;
          align-items: start;
        }

        @media (min-width: 1024px) {
          .faq-dashboard {
            grid-template-columns: 1fr 1.2fr;
            background: #fcfcfc;
            padding: 40px;
            border-radius: 40px;
            border: 1px solid #f3f4f6;
          }
        }

        /* --- QUESTION LIST --- */
        .faq-list {
          display: flex;
          flex-direction: column;
          gap: 10px;
        }

        .faq-btn {
          width: 100%;
          padding: 24px;
          text-align: left;
          background: white;
          border: 1px solid #f3f4f6;
          border-radius: 20px;
          font-size: 1rem;
          font-weight: 800;
          color: var(--dark);
          cursor: pointer;
          transition: all 0.4s ease;
          display: flex;
          justify-content: space-between;
          align-items: center;
          text-transform: uppercase;
        }

        .faq-btn.active {
          background: var(--dark);
          color: white;
          border-color: var(--dark);
          transform: translateX(10px);
        }

        .faq-btn span {
          color: var(--pepa-yellow);
          font-size: 1.2rem;
          transition: 0.4s;
        }

        .faq-btn.active span { transform: rotate(45deg); }

        /* --- ANSWER DISPLAY PANEL --- */
        .faq-display-panel {
          position: sticky;
          top: 120px;
          background: white;
          padding: 60px 40px;
          border-radius: 30px;
          min-height: 350px;
          display: flex;
          flex-direction: column;
          justify-content: center;
          box-shadow: 0 30px 60px rgba(0,0,0,0.05);
          border-right: 10px solid var(--pepa-yellow);
          /* Geometric Cut */
          clip-path: polygon(0% 0%, 100% 0%, 100% 90%, 90% 100%, 0% 100%);
        }

        .display-title {
          font-size: 1.8rem;
          font-weight: 950;
          margin-bottom: 20px;
          line-height: 1.2;
          color: var(--dark);
        }

        .display-text {
          font-size: 1.1rem;
          line-height: 1.8;
          color: #6b7280;
        }

        /* --- MOBILE ADAPTATION --- */
        @media (max-width: 1023px) {
          .faq-display-panel {
            display: none; /* Hide side panel on mobile */
          }
          .mobile-answer {
            max-height: 0;
            overflow: hidden;
            transition: all 0.4s ease;
            font-size: 0.95rem;
            color: #6b7280;
            padding: 0 24px;
          }
          .faq-btn.active + .mobile-answer {
            max-height: 200px;
            padding: 10px 24px 24px;
          }
        }

        /* --- REVEAL ANIMATIONS --- */
        .reveal-el {
          opacity: 0;
          transform: translateY(20px);
          transition: all 0.6s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .reveal-el.active-el { opacity: 1; transform: translateY(0); }
      `}</style>

      <div className="page-wrap">
        <div className="faq-title-area reveal-el">
          <span className="contact-badge">KNOWLEDGE</span>
          <h2 className="services-heading" style={{fontSize: 'clamp(2.5rem, 6vw, 4rem)', fontWeight: 950}}>
            COMMON <span>QUESTIONS</span>
          </h2>
        </div>

        <div className="faq-dashboard reveal-el">
          
          {/* Left Column: Question Buttons */}
          <div className="faq-list">
            {faqs.map((faq, index) => (
              <React.Fragment key={index}>
                <button
                  className={`faq-btn ${activeIndex === index ? 'active' : ''}`}
                  onClick={() => setActiveIndex(index)}
                >
                  {faq.question}
                  <span>+</span>
                </button>
                {/* Mobile Answer Reveal */}
                <div className="mobile-answer">
                  {faq.answer}
                </div>
              </React.Fragment>
            ))}
          </div>

          {/* Right Column: Information Panel (Desktop Only) */}
          <div className="faq-display-panel">
            <span style={{color: 'var(--pepa-yellow)', fontWeight: 900, fontSize: '0.7rem', letterSpacing: '2px'}}>EXPERT ANSWER</span>
            <h3 className="display-title">{faqs[activeIndex].question}</h3>
            <p className="display-text">{faqs[activeIndex].answer}</p>
            
            <div style={{marginTop: '30px'}}>
              <a href="#contact" style={{textDecoration: 'none', color: 'var(--dark)', fontWeight: 800, fontSize: '0.85rem'}}>
                STILL HAVE QUESTIONS? <span style={{color: 'var(--pepa-yellow)'}}>ASK PEPA →</span>
              </a>
            </div>
          </div>

        </div>
      </div>
    </section>
  );
};