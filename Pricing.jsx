import React, { useRef, useState, useEffect } from "react";
import "../styles/Pricing.css";

const plans = [
  {
    id: 1,
    color: "light",
    title: "Social Media Plan",
    features: [
      "Facebook",
      "Instagram",
      "Google My Business",
      "Twitter",
      "LinkedIn",
    ],
  },
  {
    id: 2,
    color: "brand",
    title: "Premium Plan",
    features: [
      "All in Professional Plan",
      "SEO",
      "2 Influencer Videos & Reels",
      "In-house Content Creation",
      "Website Updates",
      "Dedicated Manager",
    ],
  },
  {
    id: 3,
    color: "warm",
    title: "Professional Plan",
    features: [
      "All in Social Media Plan",
      "Facebook Ads",
      "YouTube",
      "Influencer Marketing",
      "Monthly Reports & Strategy",
    ],
  },
];

const Pricing = () => {
  const sliderRef = useRef(null);
  const [active, setActive] = useState(2);

  /* Sync active card on scroll (mobile) */
  const handleScroll = () => {
    const slider = sliderRef.current;
    if (!slider) return;

    const center = slider.scrollLeft + slider.offsetWidth / 2;
    let closest = 0;
    let minDistance = Infinity;

    Array.from(slider.children)
      .filter((el) => el.classList.contains("pricing-card"))
      .forEach((card, index) => {
        const cardCenter = card.offsetLeft + card.offsetWidth / 2;
        const distance = Math.abs(center - cardCenter);
        if (distance < minDistance) {
          minDistance = distance;
          closest = index;
        }
      });

    setActive(plans[closest].id);
  };

  /* Center middle card on mobile */
  useEffect(() => {
    if (window.innerWidth <= 768 && sliderRef.current) {
      sliderRef.current.children[1]?.scrollIntoView({
        behavior: "instant",
        inline: "center",
      });
    }
  }, []);

  return (
    <section className="pricing-section" id="pricing">
      <span className="pricing-badge">Choose the right pack</span>
      <h1 className="pricing-title">Our Flexible Pricing Plans</h1>

      <div
        className="pricing-cards"
        ref={sliderRef}
        onScroll={handleScroll}
      >
        {plans.map((plan) => (
          <div
            key={plan.id}
            className={`pricing-card card--${plan.color} ${
              active === plan.id ? "active" : ""
            }`}
            onMouseEnter={() => setActive(plan.id)}
          >
            <div className="card-outer">
              <div className="card-inner">
                <p className="card-title">{plan.title}</p>
                <ul>
                  {plan.features.map((f, i) => (
                    <li key={i}>{f}</li>
                  ))}
                </ul>
              </div>

              <a href="#contact" className="card-cta">
                Get Started →
              </a>
            </div>
          </div>
        ))}
      </div>

      {/* Dots */}
      <div className="pricing-dots">
        {plans.map((p) => (
          <span
            key={p.id}
            className={`dot ${active === p.id ? "active" : ""}`}
            onClick={() =>
              sliderRef.current.children[p.id - 1].scrollIntoView({
                behavior: "smooth",
                inline: "center",
              })
            }
          />
        ))}
      </div>

      <p className="addons">
        <strong>Add-ons:</strong> Bulk SMS · Bulk WhatsApp · Email Marketing ·
        Lead Generation · Web Design · Chatbot · ERP
      </p>
    </section>
  );
};

export default Pricing;
