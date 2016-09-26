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
		this.setDomElHeight();
	},
	reorganizeCardsInStack: function() {

		var stack = this;
		var stackXY = this.getStackPos();

		for ( var i = 0; i < this.cards.length; i++ ) {
			var card = this.cards[i];
			var reverseIndex = this.cards.length - 1 - i;
			TweenLite.to( card.domEl, 0.8,{x: stackXY.x, y: stackXY.y - (stack.DROP_GAP * (reverseIndex ) ), zIndex: stack.cards.length - reverseIndex, ease:Elastic.easeOut} );
			card.setCardXY( stackXY.x, stackXY.y - (stack.DROP_GAP * (reverseIndex ) ) );
		}
		this.setDomElHeight();
	},
	setDomElHeight: function(){
		this.domEl.style.height =  this.cards.length * this.DROP_GAP + 250 + this.gap + 'px';
	},
	removeCard: function( card ) {
		this.cards.splice( this.cards.indexOf( card ), 1 );
		this.reorganizeCardsInStack();
	},
	onTap: function ( stack ) {
		return function( ev ) {
			var card = stack.deck.currentCard;
			if ( card ) {
				card.addCardToStack( stack );
				card.animateTo( stack );
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
			card.removeCardFromStack( card.stack );
		}
	},
	onPan: function ( card ) {
		return function( ev ) {
			var offsetX = card.x + ev.deltaX;
			var offsetY = card.y + ev.deltaY;
			TweenLite.set( card.domEl, {x: offsetX, y: offsetY} );

			var hasCollided = card.findDropCollision( dropAreas ) || deck.flippedDomEl;

			hasCollided.classList.add('active-stack');

			if ( deck.flippedDomEl !== hasCollided ) {
				deck.flippedDomEl.classList.remove('active-stack');
			}
			Array.prototype.forEach.call(dropAreas, function( dropArea ){
				if ( dropArea !== hasCollided ) {
					dropArea.classList.remove('active-stack');
				}
			});
		}
	},
	onDoubleTap: function( card ) {
		return function( ev ) {
			var cardsInStack = ( card.stack ) ? card.stack.cards : [];
			if ( Stack.isPrototypeOf( card.stack ) && cardsInStack[cardsInStack.length - 1] === card ) {
				card.stack.onTap( card.stack )();
			}
		}
	},
	removeCardFromStack: function ( stack ) {

		if ( Deck.isPrototypeOf( stack ) ) {
			stack.removeFromDeck( this );
		}

		if ( Stack.isPrototypeOf( stack ) ) {
			stack.removeCard( this );
		}
		this.stack = false;
	},
	addCardToStack: function( stack ) {

		if ( this.stack ) {
			this.removeCardFromStack( this.stack )
		}

		if ( Deck.isPrototypeOf( stack ) ) {
			stack.addCardToDeck( this );
		}

		if ( Stack.isPrototypeOf( stack ) ) {
			stack.addCard( this );
		}
		this.stack = stack;
		this.setFormVal( stack.domEl.dataset.score );
	},
	animateTo: function( newStack ){
		var newStackXY = ( newStack.getStackPos ) ? newStack.getStackPos() : {x:0, y:0};
		this.setCardXY( newStackXY.x,  newStackXY.y );
		TweenLite.to( this.domEl, 0.2,{x: newStackXY.x, y: newStackXY.y, zIndex:newStack.getCards().length, ease:Power4.easeOut} );
	},
	setFormVal: function( val ){
		this.formEl.value = val;
	},
	onPanEnd: function( card ) {

		return function( ev ) {

			card.domEl.classList.remove( 'active' );
			deck.flippedDomEl.classList.remove('active-stack');

			Array.prototype.forEach.call(dropAreas, function( dropArea ){
					dropArea.classList.remove('active-stack');
			});

			var droppedArea = card.findDropCollision( dropAreas );
			if ( droppedArea ) {
				card.addCardToStack( droppedArea.stack );
				card.animateTo( droppedArea.stack );
			} else {

				if ( card !== deck.currentCard  ) {
					if ( deck.currentCard ) {
						deck.hideCurrentCard();
					}
					deck.currentCard = card;
					deck.addCardToDeck( card );
				}
				card.animateTo( deck );
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
	getCards: function() {
		return this.cardsInDeck;
	},
	initializeDeck: function() {
		var deck = this;

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
					card.addCardToStack( stacksByScore[score] );
					card.animateTo( stacksByScore[score] );
					break;
			}
		}
		this.validateForm();
	},
	validateForm: function(){
		var inputsTotal = document.querySelectorAll( '.result-score').length;

		var eightyPercent = Math.ceil( inputsTotal * 0.8 );

		var totalScored = inputsTotal - this.cardsInDeck.length;

		var remainingToEighty = eightyPercent - totalScored;

		if ( totalScored >= eightyPercent ){
			document.getElementById( 'submit-score-btn').removeAttribute('disabled');
			document.getElementById('remaining-card-counter').innerText = "You've scored enough cards to submit this query."
		} else {
			document.getElementById( 'submit-score-btn').setAttribute('disabled', 'disabled');
			document.getElementById('remaining-card-counter').innerText = 'Score at least ' + remainingToEighty + ' more cards to submit this query.'

		}

	},
	setCardCounter: function() {
		this.counterEl.innerHTML = this.cardsInDeck.length;
	},
	findPosition: function( deck, id ) {
		for (var i = 0; i < deck.cardsInDeck.length; i++) {
			if (deck.cardsInDeck[i].id == id) {
				return i;
			}
		}
		throw 'unknown card';
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

		this.validateForm();
	},
	addCardToDeck: function( cardData ) {
		var card;

		if ( Card.isPrototypeOf(cardData)  ) {
			card = cardData;
			this.cardsInDeck.push( card );
			this.currentCard = card;
			card.stack = this;
		} else {
			card = Object.create(Card, {
				cardData: {writable: true, configurable: true, value: cardData },
				deck: {writable: true, configurable: true, value: deck},
				id : {writable: true, configurable: true, value: cardData.id},
				title:  {writable: true, configurable: true, value: cardData.title},
				snippet:  {writable: true, configurable: true, value: cardData.snippet}
			});
			card.initialize();
			this.cardsInDeck.push( card );
			card.stack = this;
		}

		card.setFormVal( '' );
		this.setCardCounter();
		this.validateForm();
	},
	hideCurrentCard: function() {
		if ( this.currentCard.domEl.parentNode ) {
			this.currentCard.domEl.parentNode.removeChild( this.currentCard.domEl );
		}
		this.currentCard = false;
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


/**
 * Menu functions.
 */
$(document).ready(function(){
	$('.query-links-label').click(function(){
		$('.query-links-content').toggle();
	});

});
