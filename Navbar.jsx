import React, { useState, useEffect } from "react";
import logoImg from "../pepa_logo_1.png";
import "../styles/Navbar.css";

const Navbar = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [rotate, setRotate] = useState(false);

  // Slow logo rotation
  useEffect(() => {
    const interval = setInterval(() => {
      setRotate(true);
      setTimeout(() => setRotate(false), 8000);
    }, 10000);
    return () => clearInterval(interval);
  }, []);

  // Scroll to top when brand clicked
  const handleBrandClick = (e) => {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: "smooth" });
    setIsOpen(false);
  };

  return (
    <header className="nav-wrapper">
      <nav className="floating-nav">

        {/* BRAND */}
        <a href="/" className="logo-link" onClick={handleBrandClick}>
          <img
            src={logoImg}
            alt="PEPA"
            className={`logo-symbol ${rotate ? "slow-spin" : ""}`}
          />
          <span className="brand-name">
            pepa<span className="brand-dot">.</span>
          </span>
        </a>

        {/* CENTER LINKS (DESKTOP) */}
        <div className="nav-links desktop-only">
          <a href="#services">Services</a>
          <a href="#work">Our Work</a>
          <a href="#pricing">Plans</a>
          <a href="#faqs">FAQs</a>
          <a href="#contact">Contact</a>
        </div>

        {/* ACTIONS */}
        <div className="nav-actions">
          <a href="#call" className="cta-pill desktop-only">
            Book a Call
            <span className="arrow">↗</span>
          </a>

          {/* HAMBURGER / X */}
          <button
            className={`menu-toggle ${isOpen ? "open" : ""}`}
            onClick={() => setIsOpen(!isOpen)}
            aria-label="Toggle Menu"
          >
            <span></span>
            <span></span>
            <span></span>
          </button>
        </div>
      </nav>

      {/* MOBILE MENU */}
      {isOpen && (
        <div className="mobile-menu">
          <a href="#services" onClick={() => setIsOpen(false)}>Services</a>
          <a href="#work" onClick={() => setIsOpen(false)}>Our Work</a>
          <a href="#pricing" onClick={() => setIsOpen(false)}>Plans</a>
          <a href="#faqs" onClick={() => setIsOpen(false)}>FAQs</a>
          <a href="#contact" onClick={() => setIsOpen(false)}>Contact</a>

          <a
            href="#contact"
            className="mobile-call-btn"
            onClick={() => setIsOpen(false)}
          >
            Book a Call
          </a>
        </div>
      )}
    </header>
  );
};

export default Navbar;
