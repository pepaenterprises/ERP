import React, { useState, useEffect } from 'react';
import '../styles/Preloader.css';

const Preloader = () => {
  const [loading, setLoading] = useState(true);
  const [fadeOut, setFadeOut] = useState(false);

  useEffect(() => {
    const handleLoad = () => {
      // Once window is loaded, wait 2 more seconds
      setTimeout(() => {
        setFadeOut(true); // Start animation
        setTimeout(() => setLoading(false), 500); // Remove from DOM
      }, 2000);
    };

    if (document.readyState === 'complete') {
      handleLoad();
    } else {
      window.addEventListener('load', handleLoad);
      return () => window.removeEventListener('load', handleLoad);
    }
  }, []);

  if (!loading) return null;

  return (
    <div className={`preloader-wrapper ${fadeOut ? 'fade-out' : ''}`}>
      <div className="preloader-container">
        <div className="content">
          <div className="circle"></div>
          <div className="circle"></div>
          <div className="circle"></div>
          <div className="circle"></div>
        </div>
        <div className="typing-container">
          <span className="typing-text">pepa......</span>
        </div>
      </div>
    </div>
  );
};

export default Preloader;