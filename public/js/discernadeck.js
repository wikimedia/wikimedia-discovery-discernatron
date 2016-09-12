var cards = document.querySelectorAll( '.card' );

var dropAreas = document.querySelectorAll( '.drop-area' );

var escapeHtml = function ( html ) {
    var text = document.createTextNode(html);
    var div = document.createElement('div');
    div.appendChild(text);
    return div.innerHTML;
}

/**
 * State machine fun time
 * stack has many cards
 * card has stack || deck
 *
 * Card can move
 *
 * Stack can click
 */

var Stack = {
	domEl: Element,
	DROP_GAP: 25,
	cards: [],
	hammerStack: {},
	deck: {},
	gap: 15,
	getCards: function(){ return this.cards },
	addCard: function( card ){
		this.cards.push( card );
	},
	removeCard: function( card ) {
		//( this.gap - this.DROP_GAP < 0 ) ? this.gap = 15 : this.gap -= this.DROP_GAP ;
		this.cards.splice( this.cards.indexOf( card ), 1 );

		var stack = this;
		var stackXY = this.getStackPos();

		for ( var i = 0; i < this.cards.length; i++ ) {
			var card = this.cards[i];
			var reverseIndex = this.cards.length - i ;
			TweenLite.to( card.domEl, 0.8,{x: stackXY.x, y: stackXY.y - (stack.DROP_GAP * (reverseIndex -1 ) ), zIndex: stack.cards.length - reverseIndex, ease:Elastic.easeOut} );
			card.setCardXY( stackXY.x, stackXY.y + stack.gap - (( reverseIndex - 1) * stack.DROP_GAP ));
		}
	},
	onTap: function tapOnDropArea( stack ) {
		return function( ev ) {
			if ( stack.deck.currentCard ) {
				stack.deck.currentCard.moveCardToStack( false, stack );
			} else {
				stack.deck.revealCard(stack.deck)();
			}
		}
	},
	getStackPos: function () {
		var stackX = parseInt( window.getComputedStyle( this.domEl )[ 'left'].match( /\-\d+|\d+/g )[ 0 ] );
		var stackY = parseInt( window.getComputedStyle( this.domEl )[ 'top' ].match( /\-\d+|\d+/g )[ 0 ] );
		return { x: stackX, y: stackY + this.gap + ( this.cards.length * this.DROP_GAP ) }
	},
	initialize: function() {
		var hammerStack = this.hammerStack = new Hammer( this.domEl );
		hammerStack.on( 'tap', this.onTap( this ) );
	}
};

var Card = {
    cardData: {id: '', title:'', snippet:''},
	domEl: Element,
    formEl: Element,
	hammerCard: {},
	x: 0,
	y: 0,
	stack: false,
	deck: false,
	setCardXY: function(x, y){
		this.x = x;
		this.y = y;
	},
	isCollision: function ( dropAreaEl ) {

		var a = this.domEl.getBoundingClientRect();
		var b = dropAreaEl.getBoundingClientRect();

		if (
			a.left < b.left + b.width &&
			a.left + a.width > b.left &&
			a.top < b.top + b.height &&
			a.height + a.top > b.top
		) {
			return dropAreaEl;
		} else {
			return false;
		}
	},
	findDropCollision: function ( dropAreas ) {
		var droppedArea = false;
		for ( var i = 0; i < dropAreas.length; i++ ) {
			droppedArea = this.isCollision( dropAreas[i] );
			if (droppedArea) break;
		}
		return droppedArea;
	},
	onPanStart: function( card ) {
		return function( ev ) {
			card.domEl.classList.add('active');
			if (card.stack) {
				card.stack.removeCard( card );
				card.stack = false;
			}
		}
	},
	onPan: function ( card ) {
		return function( ev ) {
			var offsetX = card.x + ev.deltaX;
			var offsetY = card.y + ev.deltaY;
			TweenLite.set( card.domEl, {x: offsetX, y: offsetY} );
		}
	},
	moveCardToStack: function( oldStack, newStack ){
		this.deck.removeFromDeck( this );
		newStack.addCard(this);
		this.stack = newStack;
		var droppedAreaXY = newStack.getStackPos();
		this.setCardXY( droppedAreaXY.x,  droppedAreaXY.y );
		TweenLite.to( this.domEl, 0.8,{x: this.x, y: this.y, zIndex:this.stack.getCards().length - 1, ease:Elastic.easeOut} );
        this.formEl.value = newStack.domEl.attributes.getNamedItem('data-score').value;
	},
	onPanEnd: function( card ) {

		return function( ev ) {

			card.domEl.classList.remove( 'active' );

			var droppedArea = card.findDropCollision( dropAreas );
			if ( droppedArea ) {
				card.moveCardToStack( card.stack, droppedArea.stack );
			} else {
				// jump back to deck
				if ( card.stack ) {
					card.stack.removeCard();
					card.stack = false;
				}
				card.setCardXY( 0, 0 );
				TweenLite.to( card.domEl, 0.8,{ x:"0", y:"0", ease:Elastic.easeOut } );
                this.formEl.value = "";
			}
		}
	},
	createCardDOM: function() {
		var el = document.createElement('div'),
            link = window.scoringData.baseWikiUrl + '/' + this.cardData.title;
            snippet = this.cardData.snippet.split('\uE000').join('<b>').split('\uE001').join('</b>');
		el.classList.add('card');
        // note this isn't safe from XSS. should use document.createElement
		el.innerHTML = "<a href='" + link + "'>" + this.cardData.title + "</a><p>" + snippet + "</p>";
		this.domEl = el;
		document.querySelector( '.stack' ).appendChild( this.domEl );
	},
	initialize: function(){
		this.createCardDOM();
        this.formEl = document.getElementById( 'result_' + this.cardData.id );
		var hammerCard = this.hammerCard = new Hammer( this.domEl );
		hammerCard.get( 'pan' ).set( { direction: Hammer.DIRECTION_ALL } );
		hammerCard.on( 'panstart', this.onPanStart( this ) );
		hammerCard.on( 'pan', this.onPan( this ) );
		hammerCard.on( 'panend', this.onPanEnd( this ) );
	}
};

var Deck = {
	domEl: Element,
	counterEl: Element,
	cardsInDeck: [],
	hammerDeck: Object,
	currentCard: false,
	initializeDeck: function() {
		// maybe do some ajax stuff?
		var deck = this;
		window.setTimeout( function(){

			deck.cardsInDeck = window.scoringData.results;
			deck.setCardCounter();
			deck.hammerDeck = new Hammer( deck.domEl );
			deck.hammerDeck.on( 'tap', deck.revealCard( deck ) );

		}, 500 )
	},
	setCardCounter: function() {
		this.counterEl.innerHTML = this.cardsInDeck.length;
	},
	revealCard: function( deck ) {

		return function( ev ) {
			if ( deck.currentCard ) {
				deck.moveCardToBottom();
			}

			if ( deck.cardsInDeck.length > 0 ) {

				var card = Object.create(Card, {
					cardData: {writable: true, configurable: true, value: deck.cardsInDeck[0]},
					deck: {writable: true, configurable: true, value: deck}
				});
				card.initialize();
				deck.currentCard = card;
			}
		}
	},
	removeFromDeck: function( card ) {
		var cardIndex = this.cardsInDeck.indexOf( card.cardData );
		if ( cardIndex >= 0 ) {
			this.cardsInDeck.splice( this.cardsInDeck.indexOf( card.cardData ), 1 );
			this.currentCard = false;
			this.setCardCounter();
		}
	},
	moveCardToBottom: function(){
		var cards = this.cardsInDeck;
		var currentCard = cards.shift();
		this.cardsInDeck.push(currentCard);
		this.currentCard.domEl.parentNode.removeChild( this.currentCard.domEl );
		this.currentCard = false;
	}
};

var deck = Object.create( Deck, {
	domEl: {writable: true, configurable: true, value: document.querySelector('.card-deck') },
	counterEl:  {writable: true, configurable: true, value: document.querySelector('.deck-counter') },
});

deck.initializeDeck();


var stacks = document.querySelectorAll( '.drop-area' );
var globalStackAccessor = [];
for ( var i = 0; i < stacks.length; i++ ) {
	var stack = Object.create( Stack, {
		domEl: {writable: true, configurable: true, value: stacks[i] },
		deck: {writable: true, configurable: true, value: deck },
		cards: {writable: true, configurable: true, value: [] }
	});
	stacks[i].stack = stack;
	stacks[i].stack.initialize();
	globalStackAccessor.push( stack )

}

document.querySelector( '.info .query' ).innerText = window.scoringData.query.query;
