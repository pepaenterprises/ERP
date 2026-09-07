import React, { useEffect } from "react";
import Navbar from "./components/Navbar";
import Preloader from "./components/Preloader";
import Footer from "./components/Footer";

import {
  Hero,
  Services,
  Work,
  Achievements,
  About,
  FAQ,
} from "./components/Sections";

import  Contact from "./components/Contact"

import "./styles/Global.css";

function App() {
  useEffect(() => {
    const reveals = document.querySelectorAll(".reveal");

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add("active");
          }
        });
      },
      { threshold: 0.15 }
    );

    reveals.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  return (
    <>
      <Preloader />
      <Navbar />

      <main className="main-container">
        <Hero />
        <Services />
        <Work />
        <Achievements />
        <About />
        <FAQ />
        <Contact />
      </main>

      <Footer />
    </>
  );
}

export default App;
