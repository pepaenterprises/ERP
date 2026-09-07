import React, { useState, useEffect, useRef } from "react";

const Contact = () => {
  const nameRef = useRef(null);
  const sectionRef = useRef(null);

  const [form, setForm] = useState({
    name: "",
    email: "",
    phone: "",
    message: "",
  });

  // Infinite Scroll Entry Animation
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

    const elements = sectionRef.current?.querySelectorAll('.reveal-el');
    elements?.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  /* Autofill email from newsletter */
  useEffect(() => {
    const storedEmail = sessionStorage.getItem("newsletterEmail");
    if (storedEmail) {
      setForm((prev) => ({ ...prev, email: storedEmail }));
      sessionStorage.removeItem("newsletterEmail");
      setTimeout(() => {
        nameRef.current?.focus();
      }, 300);
    }
  }, []);

  const handleChange = (e) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const phoneNumber = "917760757383"; 
    const message = `Hello PEPA,\n\nI am ${form.name}.\nMy phone number is ${form.phone}.\n${form.email ? `My email is ${form.email}.` : ""}\n\nI would like to say:\n${form.message}\n\nThank you.`;
    const whatsappURL = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
    window.open(whatsappURL, "_blank");
    setForm({ name: "", email: "", phone: "", message: "" });
  };

  // 3D Tilt for Form Card
  const handleMouseMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width - 0.5) * 10;
    const y = ((e.clientY - rect.top) / rect.height - 0.5) * -10;
    e.currentTarget.style.transform = `perspective(1000px) rotateX(${y}deg) rotateY(${x}deg) scale3d(1.02, 1.02, 1.02)`;
  };

  const handleMouseLeave = (e) => {
    e.currentTarget.style.transform = `perspective(1000px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)`;
  };

  return (
    <section id="contact" className="contact-page" ref={sectionRef}>
      <style>{`
        .contact-page {
          min-height: 100vh;
          padding: 100px 0;
          background: radial-gradient(circle at top, rgba(254, 224, 100, 0.28), #ffffff 65%);
          display: flex;
          align-items: center;
          overflow: hidden;
        }

        .contact-container {
          display: grid;
          grid-template-columns: 1fr;
          gap: 60px;
          align-items: center;
        }

        @media (min-width: 1024px) {
          .contact-container { grid-template-columns: 1fr 1fr; gap: 100px; }
        }

        /* --- ANIMATIONS --- */
        .reveal-el {
          opacity: 0;
          transform: translateY(30px);
          transition: all 0.8s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .reveal-el.active {
          opacity: 1;
          transform: translateY(0);
        }

        .contact-heading {
          font-size: clamp(2.5rem, 6vw, 4rem);
          font-weight: 950;
          text-transform: uppercase;
          letter-spacing: -2px;
          line-height: 1;
        }

        .contact-heading span {
          color: var(--pepa-yellow);
          -webkit-text-stroke: 1px var(--dark);
        }

        .contact-details { margin-top: 40px; }
        .contact-details a {
          display: block;
          font-size: 1.2rem;
          font-weight: 800;
          color: var(--dark);
          text-decoration: none;
          margin-bottom: 10px;
          transition: 0.3s;
        }
        .contact-details a:hover { color: var(--pepa-yellow); transform: translateX(5px); }

        /* --- THE FORM CARD (3D) --- */
        .contact-right {
          background: rgba(255, 255, 255, 0.9);
          backdrop-filter: blur(20px);
          padding: 50px;
          border-radius: 40px;
          border: 1px solid rgba(0,0,0,0.05);
          box-shadow: 0 40px 100px rgba(0,0,0,0.1);
          transition: transform 0.1s ease-out;
        }

        .contact-form { display: flex; flex-direction: column; gap: 15px; }
        .contact-form input, .contact-form textarea {
          padding: 18px;
          border-radius: 15px;
          border: 1px solid #e5e7eb;
          background: #fcfcfc;
          font-family: inherit;
          font-size: 1rem;
          transition: 0.3s;
        }
        .contact-form input:focus, .contact-form textarea:focus {
          border-color: var(--pepa-yellow);
          box-shadow: 0 0 0 4px rgba(254, 224, 100, 0.2);
          outline: none;
        }

        .contact-submit {
          margin-top: 15px;
          padding: 18px;
          border-radius: 99px;
          background: var(--dark);
          color: white;
          border: none;
          font-weight: 900;
          text-transform: uppercase;
          letter-spacing: 1px;
          cursor: pointer;
          transition: 0.3s;
        }
        .contact-submit:hover {
          background: var(--pepa-yellow);
          color: var(--dark);
          box-shadow: 0 15px 30px rgba(254, 224, 100, 0.4);
        }

        @media (max-width: 768px) {
          .contact-right { padding: 30px 20px; }
        }
      `}</style>

      <div className="page-wrap">
        <div className="contact-container">
          {/* LEFT CONTENT */}
          <div className="contact-left">
            <span className="contact-badge reveal-el">GET IN TOUCH</span>
            <h1 className="contact-heading reveal-el">
              Let’s Build Something <br /> <span>Exceptional</span>
            </h1>

            <p className="contact-description reveal-el" style={{marginTop: '20px', fontSize: '1.1rem', color: '#4b5563'}}>
              Ready to grow your brand with strategy, creativity, and execution? 
              Connect with our team today.
            </p>

            <div className="contact-details reveal-el">
              <a href="tel:+917760757383">+91 77607 57383</a>
              <a href="mailto:info@pepa.co.in">info@pepa.co.in</a>
              <p style={{fontWeight: 700, opacity: 0.6}}>Bangalore, India</p>
            </div>
          </div>

          {/* RIGHT FORM */}
          <div 
            className="contact-right reveal-el"
            onMouseMove={handleMouseMove}
            onMouseLeave={handleMouseLeave}
          >
            <form className="contact-form" onSubmit={handleSubmit}>
              <input
                ref={nameRef}
                type="text"
                name="name"
                placeholder="Your Name *"
                required
                value={form.name}
                onChange={handleChange}
              />

              <input
                type="email"
                name="email"
                placeholder="Email (optional)"
                value={form.email}
                onChange={handleChange}
              />

              <input
                type="tel"
                name="phone"
                placeholder="Phone Number *"
                required
                value={form.phone}
                onChange={handleChange}
              />

              <textarea
                name="message"
                placeholder="Tell us about your project *"
                required
                rows="4"
                value={form.message}
                onChange={handleChange}
              />

              <button type="submit" className="contact-submit">
                Send Message →
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Contact;