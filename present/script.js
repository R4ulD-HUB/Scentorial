document.addEventListener('DOMContentLoaded', function() {
    const carousel = document.querySelector('.carousel');
    const items = document.querySelectorAll('.carousel-item');
    const prevBtn = document.querySelector('.prev-btn');
    const nextBtn = document.querySelector('.next-btn');
    const indicators = document.querySelectorAll('.indicator');
    
    let currentIndex = 0;
    const totalItems = items.length;
    
    function updateCarousel() {
        carousel.style.transform = `translateX(-${currentIndex * 100}%)`;
        
        indicators.forEach((indicator, index) => {
            if (index === currentIndex) {
                indicator.classList.add('active');
            } else {
                indicator.classList.remove('active');
            }
        });
    }
    
    prevBtn.addEventListener('click', () => {
        currentIndex = (currentIndex - 1 + totalItems) % totalItems;
        updateCarousel();
    });
    
    nextBtn.addEventListener('click', () => {
        currentIndex = (currentIndex + 1) % totalItems;
        updateCarousel();
    });
    
    indicators.forEach(indicator => {
        indicator.addEventListener('click', () => {
            currentIndex = parseInt(indicator.getAttribute('data-index'));
            updateCarousel();
        });
    });
    
    setInterval(() => {
        currentIndex = (currentIndex + 1) % totalItems;
        updateCarousel();
    }, 5000);
});
document.getElementById("whatsappButton").addEventListener("click", function() {
  const telefono = "51934671704"; 
  const mensaje = `
    ¡Hola! Estoy interesad@ en comprar *One Million de Paco Rabanne*.  
    • ¿Tienen disponibilidad en la edición clásica?  
    • ¿Ofrecen envío gratuito o muestras?
    • ¡Quiero aprovechar su mejor precio!
  `;

  const mensajeCodificado = encodeURIComponent(mensaje);
  window.open(`https://wa.me/${telefono}?text=${mensajeCodificado}`, "_blank");
});
