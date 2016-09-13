var dropAreas = document.querySelectorAll( '.drop-area' );

var stacks = document.querySelectorAll( '.drop-area' );

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
	onTap: function ( stack ) {
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
	onDoubleTap: function( card ) {
		return function( ev ) {
			if ( card.stack && card.stack.cards.indexOf(card) === card.stack.cards.length - 1 ) {
				card.stack.onTap( card.stack )();
			}
		}
	},
	moveCardToStack: function( oldStack, newStack ){
		this.deck.removeFromDeck( this );
		newStack.addCard(this);
		this.stack = newStack;
		var droppedAreaXY = newStack.getStackPos();
		this.setCardXY( droppedAreaXY.x,  droppedAreaXY.y );
		TweenLite.to( this.domEl, 0.2,{x: this.x, y: this.y, zIndex:this.stack.getCards().length - 1, ease:Power4.easeOut} );
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
                if ( deck.currentCard !== card ) {
    				if ( deck.currentCard ) {
    					deck.moveCardToBottom();
    				}
    				deck.currentCard = card;
    				deck.cardsInDeck.unshift(card.cardData);
				    card.formEl.value = "";
                }
				card.setCardXY( 0, 0 );
				TweenLite.to( card.domEl, 0.8,{ x:"0", y:"0", ease:Elastic.easeOut } );
				if (deck.cardsInDeck.length > 1) {
					deck.domEl.classList.remove( 'empty' );
				}
			}
		}
	},
	createCardDOM: function() {
		var i, b,
			el = document.createElement('div'),
			snippetPieces = this.cardData.snippet.split('\uE000').map(function (s) { return s.split('\uE001') }),

			a = document.createElement('a'),
			p = document.createElement('p');

		a.setAttribute('target', '_blank');
		a.setAttribute('href', window.scoringData.baseWikiUrl + '/' + this.cardData.title);
		a.appendChild(document.createTextNode(this.cardData.title));

		/**
		 * The snippet has markers that indicate which part should be bolded,
		 * by splitting above we have converted
		 *   -some+ text that should be -bold+
		 * into
		 *  [[""], ["some", " text that should be "], ["bold, ""]]
		 * This loop then works through those pieces and bolds the appropriate parts.
		 */
		for (i = 0; i < snippetPieces.length; i++) {
			if ( snippetPieces[i].length == 1 ) {
				if (snippetPieces[i][0].length > 0) {
					p.appendChild(document.createTextNode(snippetPieces[i][0]));
				}
			} else {
				b = document.createElement('b');
				b.appendChild(document.createTextNode(snippetPieces[i][0]));
				p.appendChild(b);
				if (snippetPieces[i][1].length > 0) {
					p.appendChild(document.createTextNode(snippetPieces[i][1]));
				}
			}
		}

		el.appendChild(a);
		el.appendChild(p);
		el.classList.add('card');

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
		hammerCard.on( 'tap', this.onDoubleTap( this ) );
	}
};

var Deck = {
	domEl: Element,
	counterEl: Element,
	cardsInDeck: [],
	hammerDeck: Object,
	currentCard: false,
	initializeDeck: function( onDone ) {
		// maybe do some ajax stuff?
		var deck = this;
		window.setTimeout( function(){

			deck.cardsInDeck = window.scoringData.results;
			deck.setCardCounter();
			deck.hammerDeck = new Hammer( deck.domEl );
			deck.hammerDeck.on( 'tap', deck.revealCard( deck ) );
			onDone && onDone();
			deck.revealCard(deck)();
		}, 500 )
	},
	setCardCounter: function() {
		this.counterEl.innerHTML = this.cardsInDeck.length;
	},
	createCard: function( deck, position ) {
		var card = Object.create(Card, {
			cardData: {writable: true, configurable: true, value: deck.cardsInDeck[position]},
			deck: {writable: true, configurable: true, value: deck}
		});
		card.initialize();
		return card;
	},
	findPosition: function( deck, id ) {
		for (var i = 0; i < deck.cardsInDeck.length; i++) {
			if (deck.cardsInDeck[i].id == id) {
				return i;
			}
		}
		throw "unknown card";
	},
	revealCard: function( deck ) {

		return function( ev ) {
			if ( deck.currentCard ) {
				deck.moveCardToBottom();
			}

			if ( deck.cardsInDeck.length > 0 ) {
				var card = deck.createCard(deck, 0);
				deck.currentCard = card;
			}
			if ( deck.cardsInDeck.length <= 1 ) {
				deck.domEl.classList.add( 'empty' );
			}

		}
	},
	removeFromDeck: function( card ) {
		var cardIndex = this.cardsInDeck.indexOf( card.cardData );
		if ( cardIndex >= 0 ) {
			this.cardsInDeck.splice( this.cardsInDeck.indexOf( card.cardData ), 1 );
			this.currentCard = false;
			this.setCardCounter();
			this.revealCard( this )();
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

deck.initializeDeck( function () {
	// assign already scored cards
	var inputs = document.querySelectorAll( 'input.result-score' ),
		stacksByScore = {};
	if (inputs.length === 0) {
		return;
	}
	for ( var i = 0; i < stacks.length; i++) {
		var stackEl = stacks[i];
		stacksByScore[stackEl.attributes.getNamedItem('data-score').value] = stackEl.stack;
	}
	for ( var i = 0; i < inputs.length; i++ ) {
		switch(inputs[i].value) {
		case '0':
		case '1':
		case '2':
		case '3':
			var position = deck.findPosition(deck, inputs[i].attributes.getNamedItem('data-id').value),
				card = deck.createCard(deck, position),
				score = inputs[i].value;
			card.moveCardToStack(false, stacksByScore[score]);
			break;
		}
	}
});


for ( var i = 0; i < stacks.length; i++ ) {
	var stack = Object.create( Stack, {
		domEl: {writable: true, configurable: true, value: stacks[i] },
		deck: {writable: true, configurable: true, value: deck },
		cards: {writable: true, configurable: true, value: [] }
	});
	stacks[i].stack = stack;
	stacks[i].stack.initialize();
}

