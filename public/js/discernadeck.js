var dropAreas = document.querySelectorAll( '.drop-area' );

var stacks = document.querySelectorAll( '.drop-area' );

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
	reorganizeCardsInStack: function() {

		var stack = this;
		var stackXY = this.getStackPos();

		for ( var i = 0; i < this.cards.length; i++ ) {
			var card = this.cards[i];
			var reverseIndex = this.cards.length - i ;
			TweenLite.to( card.domEl, 0.8,{x: stackXY.x, y: stackXY.y - (stack.DROP_GAP * (reverseIndex -1 ) ), zIndex: stack.cards.length - reverseIndex, ease:Elastic.easeOut} );
			card.setCardXY( stackXY.x, stackXY.y + stack.gap - (( reverseIndex - 1) * stack.DROP_GAP ));
		}

	},
	removeCard: function( card ) {
		this.cards.splice( this.cards.indexOf( card ), 1 );
		this.reorganizeCardsInStack();
	},
	onTap: function ( stack ) {
		return function( ev ) {
			if ( stack.deck.currentCard ) {
				stack.deck.currentCard.moveCardToStack( stack.deck, stack );
			} else {
				stack.deck.revealCard(stack.deck)();
			}
		}
	},
	getStackPos: function () {
		var stackX = parseInt( window.getComputedStyle( this.domEl )[ 'left'].match( /\-\d+|\d+/g )[ 0 ], 10 );
		var stackY = parseInt( window.getComputedStyle( this.domEl )[ 'top' ].match( /\-\d+|\d+/g )[ 0 ], 10 );
		return { x: stackX, y: stackY + this.gap + ( this.cards.length * this.DROP_GAP ) }
	},
	initialize: function() {
		var hammerStack = this.hammerStack = new Hammer( this.domEl );
		hammerStack.on( 'tap', this.onTap( this ) );
	}
};

var Card = {
	id: '',
	title: '',
	snippet: '',
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
	hasCollidedWith: function ( dropAreaEl ) {

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
			droppedArea = this.hasCollidedWith( dropAreas[i] );
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

		if ( this.deck === oldStack || !oldStack) {
			this.deck.removeFromDeck( this );
		}

		if ( this.deck === newStack ) {
			this.deck.addCardToDeck( this );
		}

		if ( oldStack && this.deck !== oldStack ) {
			this.stack.removeCard( this );
		}

		newStack.addCard(this);

		this.stack = newStack;

		var newStackXY = newStack.getStackPos();
		this.setCardXY( newStackXY.x,  newStackXY.y );
		TweenLite.to( this.domEl, 0.2,{x: newStackXY.x, y: newStackXY.y, zIndex:newStack.getCards().length - 1, ease:Power4.easeOut} );
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
					deck.addCardToDeck( card );
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
			snippetPieces = this.snippet.split('\uE000').map(function (s) { return s.split('\uE001') });


		var a = document.createElement('a');
		var p = document.createElement('p');

		a.setAttribute('target', '_blank');
		a.setAttribute('href', window.scoringData.baseWikiUrl + '/' + this.title);
		a.appendChild(document.createTextNode(this.title));

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
				b = document.createElement('strong');
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
	},
	renderCardIn: function( domEl ) {
		domEl.appendChild( this.domEl );
	},
	initialize: function(){
		this.createCardDOM();
		this.formEl = document.getElementById( 'result_' + this.id );
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
	flippedDomEl: Element,
	cardsInDeck: [],
	hammerDeck: Object,
	currentCard: false,
	initializeDeck: function() {
		var deck = this;
		//deck.cardsInDeck = window.scoringData.results;

		window.scoringData.results.forEach(function( cardObj, i) {
			deck.addCardToDeck( cardObj );
		} );
		deck.hammerDeck = new Hammer( deck.domEl );
		deck.hammerDeck.on( 'tap', deck.revealCard( deck ) );
		//reveal the first card

		deck.revealCard(deck)();
		deck.setCardCounter();
		deck.assignExistingScores();
	},
	assignExistingScores: function() {
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
		for ( var i = inputs.length -1; i >= 0; i-- ) {
			switch(inputs[i].value) {
				case '0':
				case '1':
				case '2':
				case '3':
					var position = deck.findPosition(deck, parseInt( inputs[i].dataset.id, 10 ) ),
						score = inputs[i].value;
					var card = deck.cardsInDeck[position];
					card.renderCardIn( deck.flippedDomEl );
					card.moveCardToStack(this, stacksByScore[score]);
					break;
			}
		}
	},
	setCardCounter: function() {
		this.counterEl.innerHTML = this.cardsInDeck.length;
	},
	findPosition: function( deck, id ) {
		var index = -1;

		for (var i = 0; i < deck.cardsInDeck.length; i++) {
			if (deck.cardsInDeck[i].id == id) {
				index = i;
			}
		}
		return index;
	},
	revealCard: function( deck ) {

		return function( ev ) {
			if ( deck.currentCard ) {
				deck.moveCardToBottom();
			}

			if ( deck.cardsInDeck.length > 0 ) {
				deck.cardsInDeck[0].renderCardIn( deck.flippedDomEl );
				deck.currentCard = deck.cardsInDeck[0];
			}
			if ( deck.cardsInDeck.length <= 1 ) {
				deck.domEl.classList.add( 'empty' );
			}

		}
	},
	removeFromDeck: function( card ) {
		var cardIndex = this.findPosition( this, card.id);
		if ( cardIndex >= 0 ) {
			this.cardsInDeck.splice( this.cardsInDeck.indexOf( card ), 1 );
			this.currentCard = false;
			this.setCardCounter();
			this.revealCard( this )();
		}

		if ( this.cardsInDeck.length > 0 ) {
			this.currentCard = this.cardsInDeck[0];
		}
	},
	addCardToDeck: function( cardData ) {

		if ( cardData.isPrototypeOf( Card )  ) {
			this.cardsInDeck.push( cardData );
			this.currentCard = cardData;
		} else {
			var card = Object.create(Card, {
				cardData: {writable: true, configurable: true, value: cardData },
				deck: {writable: true, configurable: true, value: deck},
				id : {writable: true, configurable: true, value: cardData.id},
				title:  {writable: true, configurable: true, value: cardData.title},
				snippet:  {writable: true, configurable: true, value: cardData.snippet}
			});
			card.initialize();
			this.cardsInDeck.push( card );
		}

		this.setCardCounter();
	},
	moveCardToBottom: function(){
		var cards = this.cardsInDeck;
		var currentCard = cards.shift();
		this.cardsInDeck.push(currentCard);
		if ( this.currentCard.domEl.parentNode ) {
			this.currentCard.domEl.parentNode.removeChild( this.currentCard.domEl );
		}
		this.currentCard = false;
	}
};

var deck = Object.create( Deck, {
	domEl: {writable: true, configurable: true, value: document.querySelector('.card-deck') },
	counterEl:  {writable: true, configurable: true, value: document.querySelector('.deck-counter') },
	flippedDomEl: {writable: true, configurable: true, value: document.querySelector('.card-deck').nextElementSibling }
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

deck.initializeDeck();
