function openTrip(){

    window.location.href="trip-details.html";

}



// Search trips

function searchTrips(){

    let input =
    document.getElementById("searchTrip")
    .value
    .toLowerCase();



    let cards =
    document.querySelectorAll("#tripList .trip-card");



    cards.forEach(card=>{


        let text =
        card.innerText.toLowerCase();



        if(text.includes(input))
        {

            card.style.display="block";

        }

        else
        {

            card.style.display="none";

        }


    });


}




// Filter trips

function filterTrips(type){


let cards =
document.querySelectorAll("#tripList .trip-card");



cards.forEach(card=>{


let privacy =
card.querySelector(".trip-type")
.innerText;



if(type==="All" || privacy.includes(type))
{

card.style.display="block";

}

else

{

card.style.display="none";

}


});


}
